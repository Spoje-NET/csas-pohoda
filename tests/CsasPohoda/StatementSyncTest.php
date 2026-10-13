<?php

declare(strict_types=1);

/**
 * This file is part of the CsasPohoda package
 *
 * https://github.com/Spoje-NET/csas-pohoda
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Test\SpojeNet\CsasPohoda;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpojeNet\CsasPohoda\GraphApiException;
use SpojeNet\CsasPohoda\GraphSharePointClient;
use SpojeNet\CsasPohoda\PohodaAttachments;
use SpojeNet\CsasPohoda\StatementSync;

/**
 * StatementSync with a scripted process runner instead of the real tools.
 */
class FakeSync extends StatementSync
{
    /** @var array<int, array{code: int, stdout: string, stderr: string}> */
    public array $results = [];

    /** @var array<int, array<int, string>> */
    public array $commands = [];

    /** @var array<int, array<string, string>> */
    public array $envs = [];

    /** Optional callback(command): void run before the scripted result is returned. */
    public ?\Closure $onRun = null;

    public ?GraphSharePointClient $client = null;
    public ?PohodaAttachments $attachmentsDb = null;

    /** @var array<int, string> */
    public array $transactions = ['ABO_1_2'];

    protected function sharePointClient(array $env): ?GraphSharePointClient
    {
        return $this->client;
    }

    protected function attachments(array $env): ?PohodaAttachments
    {
        return $this->attachmentsDb;
    }

    protected function transactionIds(string $aboFile): array
    {
        return $this->transactions;
    }

    protected function runProcess(array $command, array $env): array
    {
        $this->commands[] = $command;
        $this->envs[] = $env;

        if ($this->onRun) {
            ($this->onRun)($command);
        }

        return array_shift($this->results) ?? ['code' => 0, 'stdout' => '', 'stderr' => ''];
    }
}

/**
 * Graph client with scripted HTTP responses.
 */
class FakeGraph extends GraphSharePointClient
{
    /** @var array<int, array{method: string, url: string, body: ?string}> */
    public array $requests = [];

    /** @var array<string, int> upload file name => HTTP status to answer with */
    public array $failUploads = [];

    protected function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];

        if (str_contains($url, 'oauth2/v2.0/token')) {
            return ['code' => 200, 'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600]), 'error' => ''];
        }

        if (str_contains($url, '/sites/contoso.sharepoint.com:/sites/finance')) {
            return ['code' => 200, 'body' => json_encode(['id' => 'site-1']), 'error' => ''];
        }

        if ($method === 'PUT') {
            foreach ($this->failUploads as $name => $code) {
                if (str_contains($url, $name)) {
                    return ['code' => $code, 'body' => 'denied', 'error' => ''];
                }
            }

            return ['code' => 200, 'body' => json_encode(['id' => 'item-'.\count($this->requests), 'webUrl' => 'https://sp/web/'.basename($url), 'name' => 'x']), 'error' => ''];
        }

        if (str_ends_with($url, '/createLink')) {
            return ['code' => 200, 'body' => json_encode(['link' => ['webUrl' => 'https://sp/share/'.basename(\dirname($url))]]), 'error' => ''];
        }

        return ['code' => 404, 'body' => 'not found', 'error' => ''];
    }
}

/**
 * Pohoda SQL layer without a database.
 */
class FakeAttachments extends PohodaAttachments
{
    /** @var array<int, int> */
    public array $records = [101, 102];

    /** @var array<int, int> */
    public array $existing = [];

    public bool $failAdd = false;

    /** @var array<int, array{0: int, 1: string, 2: string}> */
    public array $added = [];

    public function findBankRecordIds(array $transactionIds): array
    {
        return $this->records;
    }

    public function hasUrlAttachment(int $bankRecordId, string $url): bool
    {
        return \in_array($bankRecordId, $this->existing, true);
    }

    public function addUrlAttachment(int $bankRecordId, string $url, string $name): bool
    {
        $this->added[] = [$bankRecordId, $url, $name];

        return !$this->failAdd;
    }
}

#[CoversClass(StatementSync::class)]
final class StatementSyncTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/csas-pohoda-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testLoadEnvFile(): void
    {
        $file = $this->tmp.'/.env';
        file_put_contents($file, "# comment\nA=1\nexport B=\"two words\"\nC='3'\n\nD=a=b\n");
        $this->assertSame(['A' => '1', 'B' => 'two words', 'C' => '3', 'D' => 'a=b'], StatementSync::loadEnvFile($file));
        $this->assertSame([], StatementSync::loadEnvFile($this->tmp.'/missing'));
    }

    public function testBuildScope(): void
    {
        $this->assertSame('2025-09-01>2025-09-15', StatementSync::buildScope('2025-09-01', '2025-09-15', 'yesterday'));
        $this->assertSame('yesterday', StatementSync::buildScope(null, null, 'yesterday'));
        $this->assertSame('last_month', StatementSync::buildScope('', null, null));
    }

    /**
     * @dataProvider invalidScopes
     */
    #[DataProvider('invalidScopes')]
    public function testBuildScopeRejectsInvalid(?string $from, ?string $to, ?string $scope): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatementSync::buildScope($from, $to, $scope);
    }

    /**
     * @return array<string, array<int, ?string>>
     */
    public static function invalidScopes(): array
    {
        return [
            'one date only' => ['2025-09-01', null, null],
            'reversed' => ['2025-09-15', '2025-09-01', null],
            'not zero padded' => ['2025-9-1', '2025-09-15', null],
            'impossible date' => ['2025-02-30', '2025-03-01', null],
            'injection in date' => ['2025-09-01', "2025-09-15\n; rm", null],
            'injection in scope' => [null, null, 'last_month; rm -rf /'],
        ];
    }

    public function testRedactHidesSecretsAlsoJsonEscaped(): void
    {
        $env = ['POHODA_PASSWORD' => 'pa/ss"žluť', 'CSAS_ACCESS_TOKEN' => 'tok3n-value', 'OTHER' => 'visible-value', 'SHORT_TOKEN' => 'abc'];
        $text = 'a pa/ss"žluť b '.json_encode('pa/ss"žluť').' '.json_encode('pa/ss"žluť', \JSON_UNESCAPED_UNICODE).' tok3n-value visible-value abc';
        $redacted = StatementSync::redact($text, $env);
        $this->assertStringNotContainsString('žluť', $redacted);
        $this->assertStringNotContainsString('u017e', $redacted);
        $this->assertStringNotContainsString('tok3n-value', $redacted);
        $this->assertStringContainsString('visible-value', $redacted);
        $this->assertStringContainsString('abc', $redacted); // too short to be treated as secret
    }

    public function testSanitizeKeepsValidStructure(): void
    {
        $report = StatementSync::sanitize(['message' => 'login s3cr3t-pass failed', 'list' => ['s3cr3t-pass']], ['POHODA_PASSWORD' => 's3cr3t-pass']);
        $this->assertSame('login *** failed', $report['message']);
        $this->assertSame(['***'], $report['list']);
    }

    public function testPrivateDirIsCreatedWithMode0700(): void
    {
        $dir = StatementSync::ensurePrivateDir($this->tmp.'/a/b');
        $this->assertSame(0700, fileperms($dir) & 0777);
    }

    public function testPrivateDirRejectsSymlink(): void
    {
        symlink($this->tmp, $this->tmp.'/link');
        $this->expectException(\RuntimeException::class);
        StatementSync::ensurePrivateDir($this->tmp.'/link');
    }

    public function testPrivateDirTightensOpenPermissions(): void
    {
        mkdir($this->tmp.'/open', 0777);
        chmod($this->tmp.'/open', 0777);
        StatementSync::ensurePrivateDir($this->tmp.'/open');
        $this->assertSame(0700, fileperms($this->tmp.'/open') & 0777);
    }

    public function testDownloadUsesPrivateRunDirAndSetsScope(): void
    {
        $sync = new FakeSync();
        $sync->onRun = function (array $command): void {
            touch(substr($command[1], 2).'/a.abo-standard');
            touch(substr($command[1], 2).'/ignored.txt');
        };
        [$files, $info] = $sync->downloadStatements('2025-09-01>2025-09-15', $this->tmp.'/out', ['CSAS_API_KEY' => 'x']);

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/a.abo-standard', $files[0]);
        $this->assertSame(0700, fileperms(\dirname($files[0])) & 0777);
        $this->assertSame(StatementSync::DOWNLOADER, $sync->commands[0][0]);
        $this->assertSame('-fabo-standard', $sync->commands[0][2]);
        $this->assertSame('2025-09-01>2025-09-15', $sync->envs[0]['CSAS_STATEMENT_SCOPE']);
        $this->assertSame(0, $info['returncode']);
    }

    public function testDownloadWithoutStatementsRemovesRunDir(): void
    {
        $sync = new FakeSync();
        [$files, $info] = $sync->downloadStatements('yesterday', $this->tmp.'/out', []);
        $this->assertSame([], $files);
        $this->assertDirectoryDoesNotExist($info['run_dir']);
    }

    public function testDownloadFailureIsRedacted(): void
    {
        $sync = new FakeSync();
        $sync->results[] = ['code' => 3, 'stdout' => '', 'stderr' => 'bad token tok3n-value'];
        [$files, $info] = $sync->downloadStatements('yesterday', $this->tmp.'/out', ['CSAS_ACCESS_TOKEN' => 'tok3n-value']);
        $this->assertNull($files);
        $this->assertStringNotContainsString('tok3n-value', $info['error']);
        $this->assertStringContainsString('bad token', $info['error']);
    }

    public function testMissingToolIsReportedByRealRunner(): void
    {
        $sync = new class() extends StatementSync {
            public const DOWNLOADER = '/nonexistent/csas-statement-downloader';
        };
        [$files, $info] = $sync->downloadStatements('yesterday', $this->tmp.'/out', []);
        $this->assertNull($files);
        $this->assertStringContainsString('Cannot run', $info['error']);
    }

    public function testImportReadsReportWrittenByTool(): void
    {
        $sync = new FakeSync();
        $sync->onRun = static function (array $command): void {
            file_put_contents(substr($command[1], 2), json_encode(['status' => 'success', 'message' => 'ok']));
        };
        [$report, $info] = $sync->importStatements(['/x/a.abo-standard', '/x/b.abo-standard'], []);
        $this->assertSame('success', $report['status']);
        $this->assertSame(StatementSync::IMPORTER, $sync->commands[0][0]);
        $this->assertStringStartsWith('-o', $sync->commands[0][1]); // options before the files
        $this->assertSame(['/x/a.abo-standard', '/x/b.abo-standard'], \array_slice($sync->commands[0], 2));
    }

    public function testImportWithoutReport(): void
    {
        $sync = new FakeSync();
        $sync->results[] = ['code' => 1, 'stdout' => '', 'stderr' => 'mServer down'];
        [$report, $info] = $sync->importStatements(['/x/a.abo-standard'], []);
        $this->assertNull($report);
        $this->assertStringContainsString('mServer down', $info['error']);
    }

    public function testRunSuccess(): void
    {
        $sync = new FakeSync();
        $sync->onRun = function (array $command): void {
            if ($command[0] === StatementSync::DOWNLOADER) {
                touch(substr($command[1], 2).'/a.abo-standard');
            } else {
                file_put_contents(substr($command[1], 2), json_encode(['status' => 'success', 'message' => 'imported']));
            }
        };
        [$code, $report] = $sync->run(['from_date' => '2025-09-01', 'to_date' => '2025-09-15', 'output_dir' => $this->tmp.'/out']);
        $this->assertSame(StatementSync::EXIT_OK, $code);
        $this->assertSame('success', $report['status']);
        $this->assertSame('imported', $report['message']);
        $this->assertSame('-d', substr($sync->commands[0][1], 0, 2));
        $this->assertSame('2025-09-01>2025-09-15', $sync->envs[0]['CSAS_STATEMENT_SCOPE']);
    }

    public function testNothingToImportIsSuccess(): void
    {
        [$code, $report] = (new FakeSync())->run(['output_dir' => $this->tmp.'/out']);
        $this->assertSame(StatementSync::EXIT_OK, $code);
        $this->assertStringContainsString('nothing to import', $report['message']);
        $this->assertSame([], $report['artifacts']['statements']);
    }

    public function testDownloadFailureExitCode(): void
    {
        $sync = new FakeSync();
        $sync->results[] = ['code' => 1, 'stdout' => '', 'stderr' => 'x'];
        [$code, $report] = $sync->run(['output_dir' => $this->tmp.'/out']);
        $this->assertSame(StatementSync::EXIT_DOWNLOAD_FAILED, $code);
        $this->assertSame('error', $report['status']);
    }

    public function testImportFailureExitCode(): void
    {
        $sync = new FakeSync();
        $sync->onRun = function (array $command): void {
            if ($command[0] === StatementSync::DOWNLOADER) {
                touch(substr($command[1], 2).'/a.abo-standard');
            } else {
                file_put_contents(substr($command[1], 2), json_encode(['status' => 'error', 'message' => 'bad']));
            }
        };
        $sync->results = [['code' => 0, 'stdout' => '', 'stderr' => ''], ['code' => 1, 'stdout' => '', 'stderr' => '']];
        [$code, $report] = $sync->run(['output_dir' => $this->tmp.'/out']);
        $this->assertSame(StatementSync::EXIT_IMPORT_FAILED, $code);
        $this->assertSame('bad', $report['message']);
    }

    public function testFileOptionSkipsDownload(): void
    {
        $abo = $this->tmp.'/a.abo-standard';
        touch($abo);
        $sync = new FakeSync();
        $sync->onRun = static function (array $command): void {
            file_put_contents(substr($command[1], 2), json_encode(['status' => 'success', 'message' => 'ok']));
        };
        [$code] = $sync->run(['file' => [$abo]]);
        $this->assertSame(StatementSync::EXIT_OK, $code);
        $this->assertCount(1, $sync->commands);
        $this->assertSame(StatementSync::IMPORTER, $sync->commands[0][0]);
        $this->assertSame(realpath($abo), $sync->commands[0][2]);
    }

    public function testFileOptionMissingFile(): void
    {
        [$code] = (new FakeSync())->run(['file' => ['/nonexistent/a.abo-standard']]);
        $this->assertSame(StatementSync::EXIT_DOWNLOAD_FAILED, $code);
    }

    public function testInvalidDates(): void
    {
        [$code] = (new FakeSync())->run(['from_date' => '2025-09-01']);
        $this->assertSame(StatementSync::EXIT_DOWNLOAD_FAILED, $code);
    }

    public function testEnvFileProvidesPeriodAndReportNeverContainsSecrets(): void
    {
        $envFile = $this->tmp.'/.env';
        file_put_contents($envFile, "FROM_DATE=2025-09-01\nTO_DATE=2025-09-30\nPOHODA_PASSWORD=s3cr3t-pass\nOUTPUT_DIR={$this->tmp}/out\n");
        $sync = new FakeSync();
        $sync->results[] = ['code' => 1, 'stdout' => '', 'stderr' => 'login s3cr3t-pass failed'];
        [$code, $report] = $sync->run(['env_file' => $envFile]);
        $this->assertSame(StatementSync::EXIT_DOWNLOAD_FAILED, $code);
        $this->assertSame('2025-09-01>2025-09-30', $sync->envs[0]['CSAS_STATEMENT_SCOPE']);
        $this->assertStringNotContainsString('s3cr3t-pass', json_encode($report));
    }

    private const SP_ENV = ['OFFICE365_TENANT' => 'contoso', 'OFFICE365_SITE' => 'finance', 'OFFICE365_CLIENTID' => 'cid', 'OFFICE365_CLSECRET' => 'sp-secret-value', 'OFFICE365_PATH' => 'Shared Documents/Bank/PDF', 'OFFICE365_PATH_ABO' => 'Shared Documents/Bank/ABO'];

    /**
     * Sync which downloads one ABO and one PDF statement and imports successfully.
     */
    private function syncWithStatement(): FakeSync
    {
        $sync = new FakeSync();
        $sync->client = new FakeGraph('contoso', 'finance', 'cid', 'sp-secret-value');
        $sync->attachmentsDb = new FakeAttachments(['DB_HOST' => 'h', 'DB_DATABASE' => 'd']);
        $sync->onRun = function (array $command): void {
            if ($command[0] === StatementSync::DOWNLOADER) {
                $format = substr($command[2], 2);
                touch(substr($command[1], 2).'/27_CZ84_CZK_2025-08-21.'.($format === 'pdf' ? 'pdf' : $format));
            } else {
                file_put_contents(substr($command[1], 2), json_encode(['status' => 'success', 'message' => 'imported']));
            }
        };

        return $sync;
    }

    public function testStatementsAreUploadedToSharePointAndLinked(): void
    {
        $sync = $this->syncWithStatement();
        $sync->attachmentsDb->existing = [102];
        [$code, $report] = $sync->run(['output_dir' => $this->tmp.'/out', 'env_file' => $this->envFile(self::SP_ENV)]);

        $this->assertSame(StatementSync::EXIT_OK, $code, json_encode($report));
        $this->assertSame('-fpdf', $sync->commands[1][2]); // PDF downloaded into the same run directory
        $this->assertSame(['27_CZ84_CZK_2025-08-21.abo-standard'], array_keys($report['artifacts']['sharepoint']['abo']));
        $this->assertStringStartsWith('https://sp/share/', $report['artifacts']['sharepoint']['pdf']['27_CZ84_CZK_2025-08-21.pdf']);

        $uploads = array_values(array_filter($sync->client->requests, static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertStringContainsString('root:/Shared%20Documents/Bank/ABO/27_CZ84_CZK_2025-08-21.abo-standard:/content', $uploads[0]['url']);
        $this->assertStringContainsString('root:/Shared%20Documents/Bank/PDF/27_CZ84_CZK_2025-08-21.pdf:/content', $uploads[1]['url']);

        $this->assertCount(1, $sync->attachmentsDb->added); // 102 was linked already
        $this->assertSame(101, $sync->attachmentsDb->added[0][0]);
        $this->assertSame('27_CZ84_CZK_2025-08-21.pdf', $sync->attachmentsDb->added[0][2]);
        $this->assertSame(['transactions' => 1, 'records' => 2, 'attached' => 1, 'existing' => 1, 'failed' => 0], $report['artifacts']['attachments']['27_CZ84_CZK_2025-08-21.abo-standard']);
        $this->assertStringNotContainsString('sp-secret-value', json_encode($report));
    }

    public function testNothingIsUploadedWithoutSharePoint(): void
    {
        $sync = $this->syncWithStatement();
        $sync->client = null;
        [$code, $report] = $sync->run(['output_dir' => $this->tmp.'/out', 'env_file' => $this->envFile(self::SP_ENV)]);
        $this->assertSame(StatementSync::EXIT_OK, $code);
        $this->assertCount(2, $sync->commands); // downloader and importer only
        $this->assertArrayNotHasKey('sharepoint', $report['artifacts']);
        $this->assertSame([], $sync->attachmentsDb->added);
    }

    public function testSharePointFailureIsReportedAfterImport(): void
    {
        $sync = $this->syncWithStatement();
        $sync->client->failUploads = ['.pdf' => 403];
        [$code, $report] = $sync->run(['output_dir' => $this->tmp.'/out', 'env_file' => $this->envFile(self::SP_ENV)]);
        $this->assertSame(StatementSync::EXIT_SHAREPOINT_FAILED, $code);
        $this->assertSame('error', $report['status']);
        $this->assertSame(403, $report['artifacts']['sharepoint']['pdf']['27_CZ84_CZK_2025-08-21.pdf']['httpCode']);
        $this->assertSame([], $sync->attachmentsDb->added); // no link without the upload
        $this->assertArrayHasKey('importer_report', $report['artifacts']); // the import was done
    }

    public function testAttachmentFailureExitCode(): void
    {
        $sync = $this->syncWithStatement();
        $sync->attachmentsDb->failAdd = true;
        [$code, $report] = $sync->run(['output_dir' => $this->tmp.'/out', 'env_file' => $this->envFile(self::SP_ENV)]);
        $this->assertSame(StatementSync::EXIT_ATTACH_FAILED, $code);
        $this->assertSame(2, $report['artifacts']['attachments']['27_CZ84_CZK_2025-08-21.abo-standard']['failed']);
    }

    public function testAttachmentsAreSkippedWithoutDatabase(): void
    {
        $sync = $this->syncWithStatement();
        $sync->attachmentsDb = null;
        [$code, $report] = $sync->run(['output_dir' => $this->tmp.'/out', 'env_file' => $this->envFile(self::SP_ENV)]);
        $this->assertSame(StatementSync::EXIT_OK, $code);
        $this->assertArrayHasKey('skipped', $report['artifacts']['attachments']);
    }

    public function testImportFailureStillReportsSharePoint(): void
    {
        $sync = $this->syncWithStatement();
        $sync->onRun = function (array $command): void {
            if ($command[0] === StatementSync::DOWNLOADER) {
                touch(substr($command[1], 2).'/27_CZ84_CZK_2025-08-21.'.substr($command[2], 2));
            } else {
                file_put_contents(substr($command[1], 2), json_encode(['status' => 'error', 'message' => 'bad']));
            }
        };
        $sync->results = [['code' => 0, 'stdout' => '', 'stderr' => ''], ['code' => 0, 'stdout' => '', 'stderr' => ''], ['code' => 1, 'stdout' => '', 'stderr' => '']];
        [$code, $report] = $sync->run(['output_dir' => $this->tmp.'/out', 'env_file' => $this->envFile(self::SP_ENV)]);
        $this->assertSame(StatementSync::EXIT_IMPORT_FAILED, $code);
        $this->assertArrayHasKey('sharepoint', $report['artifacts']);
        $this->assertSame([], $sync->attachmentsDb->added);
    }

    public function testFileOptionUsesPdfBesideTheStatement(): void
    {
        $abo = $this->tmp.'/27_CZ84_CZK_2025-08-21.abo-standard';
        touch($abo);
        touch($this->tmp.'/27_CZ84_CZK_2025-08-21.pdf');
        $sync = $this->syncWithStatement();
        [$code, $report] = $sync->run(['file' => [$abo], 'env_file' => $this->envFile(self::SP_ENV)]);
        $this->assertSame(StatementSync::EXIT_OK, $code, json_encode($report));
        $this->assertCount(1, $sync->commands); // importer only, nothing downloaded
        $this->assertCount(2, $sync->attachmentsDb->added);
    }

    public function testGraphClientFromEnv(): void
    {
        $this->assertNull(GraphSharePointClient::fromEnv(['OFFICE365_TENANT' => 'contoso']));
        $this->assertInstanceOf(GraphSharePointClient::class, GraphSharePointClient::fromEnv(self::SP_ENV));
    }

    public function testGraphClientRetriesAndThrowsOnFailure(): void
    {
        $client = new FakeGraph('contoso', 'finance', 'cid', 'secret');
        $client->failUploads = ['a.pdf' => 500];

        try {
            $client->uploadFile('dir', 'a.pdf', 'x');
            $this->fail('GraphApiException expected');
        } catch (GraphApiException $exc) {
            $this->assertSame(500, $exc->getCode());
            $this->assertSame('denied', $exc->getResponseBody());
        }

        $puts = array_filter($client->requests, static fn (array $r): bool => $r['method'] === 'PUT');
        $this->assertCount(2, $puts); // one retry
    }

    public function testAttachmentsHelpers(): void
    {
        $this->assertSame('%#ABO[_]12[_]00[[]1#%', PohodaAttachments::likePattern('ABO_12_00[1'));
        $this->assertTrue(PohodaAttachments::isConfigured(['DB_HOST' => 'h', 'DB_DATABASE' => 'd']));
        $this->assertFalse(PohodaAttachments::isConfigured(['DB_HOST' => 'h']));
    }

    /**
     * @param array<string, string> $vars
     */
    private function envFile(array $vars): string
    {
        $file = $this->tmp.'/sp.env';
        file_put_contents($file, implode("\n", array_map(static fn (string $k, string $v): string => $k.'='.$v, array_keys($vars), $vars))."\n");

        return $file;
    }
}
