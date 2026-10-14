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

namespace SpojeNet\CsasPohoda;

/**
 * Download bank statements from Česká spořitelna (CSAS) and import them into Pohoda.
 *
 * The work is done by two installed tools:
 *
 *  - csas-statement-downloader (package csas-statement-tools) saves statements in the ABO format
 *  - pohoda-abo-importer (package pohoda-abo-importer) creates the bank movements in Pohoda via mServer
 *
 * Everything is configured by environment variables (as provided by MultiFlexi credentials):
 *
 *  - CSAS_API_KEY, CSAS_ACCESS_TOKEN, CSAS_ACCOUNT_IBAN or CSAS_ACCOUNT_UUID, CSAS_SANDBOX_MODE
 *  - POHODA_URL, POHODA_USERNAME, POHODA_PASSWORD, POHODA_ICO, POHODA_BANK_IDS
 *  - FROM_DATE and TO_DATE (YYYY-MM-DD) or CSAS_STATEMENT_SCOPE (e.g. last_month, yesterday)
 *  - OUTPUT_DIR, RESULT_FILE
 *
 * When SharePoint is configured (OFFICE365_TENANT, OFFICE365_SITE, OFFICE365_CLIENTID, OFFICE365_CLSECRET,
 * OFFICE365_PATH, optional OFFICE365_PATH_ABO) the ABO and PDF statements are uploaded to it and, when the Pohoda
 * SQL Server is configured too (DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD), the link to the PDF is attached
 * to the imported bank records.
 *
 * Exit codes: 0 success (also when there is nothing to import), 1 download failed, 2 import failed,
 * 3 SharePoint upload failed, 4 attaching of the links failed.
 */
class StatementSync extends \Ease\Sand
{
    public const DEFAULT_OUTPUT_DIR = 'statements';
    public const DEFAULT_SCOPE = 'last_month';
    public const STATEMENT_FORMAT = 'abo-standard';
    public const DOWNLOADER = '/usr/bin/csas-statement-downloader';
    public const IMPORTER = '/usr/bin/pohoda-abo-importer';
    public const EXIT_OK = 0;
    public const EXIT_DOWNLOAD_FAILED = 1;
    public const EXIT_IMPORT_FAILED = 2;
    public const EXIT_SHAREPOINT_FAILED = 3;
    public const EXIT_ATTACH_FAILED = 4;
    private const DATE_RE = '/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/D';
    private const SCOPE_RE = '/^[A-Za-z_]{1,32}$/D';
    private const SECRET_NAME = '/(PASSWORD|PASSWD|TOKEN|SECRET|API_KEY|APIKEY|CREDENTIAL|^POHODA_USERNAME$|^POHODA_URL$)/i';

    /**
     * Name the object, so the log shows it instead of the inherited "Molecule".
     */
    public function __construct()
    {
        $this->setObjectName('CsasPohoda');
    }

    /**
     * Variables from a .env file (missing file means no variables).
     *
     * @return array<string, string>
     */
    public static function loadEnvFile(string $envPath): array
    {
        $envVars = [];

        if (!is_file($envPath)) {
            return $envVars;
        }

        foreach (file($envPath, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $value = trim($value);

                if (\strlen($value) >= 2 && $value[0] === substr($value, -1) && str_contains('"\'', $value[0])) {
                    $value = substr($value, 1, -1);
                }

                $envVars[trim($key)] = $value;
            }
        }

        return $envVars;
    }

    /**
     * Values of all variables which look like secrets (longest first).
     *
     * @param array<string, string> $env
     *
     * @return array<int, string>
     */
    public static function secretValues(array $env): array
    {
        $values = [];

        foreach ($env as $name => $value) {
            if (\is_string($value) && \strlen($value) >= 4 && preg_match(self::SECRET_NAME, (string) $name)) {
                $values[$value] = $value;
            }
        }

        usort($values, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $values;
    }

    /**
     * Hide the values of secret variables (also in JSON-escaped form) in text for logs and reports.
     *
     * @param array<string, string> $env
     */
    public static function redact(string $text, array $env): string
    {
        foreach (self::secretValues($env) as $value) {
            $variants = [
                $value,
                substr(json_encode($value) ?: '""', 1, -1),
                substr(json_encode($value, \JSON_UNESCAPED_UNICODE) ?: '""', 1, -1),
                substr(json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '""', 1, -1),
            ];

            foreach (array_unique($variants) as $variant) {
                if ($variant !== '') {
                    $text = str_replace($variant, '***', $text);
                }
            }
        }

        return $text;
    }

    /**
     * Return the report with all secret values removed.
     *
     * @param array<string, mixed>  $report
     * @param array<string, string> $env
     *
     * @return array<string, mixed>
     */
    public static function sanitize(array $report, array $env): array
    {
        $json = json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return json_decode(self::redact((string) $json, $env), true) ?? [];
    }

    /**
     * Create the directory (mode 0700) or verify that an existing one is safe to use:
     * a real directory (no symlink) owned by the current user and not accessible by others.
     */
    public static function ensurePrivateDir(string $path): string
    {
        if (is_link($path)) {
            throw new \RuntimeException($path.' is a symbolic link');
        }

        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create '.$path);
        }

        clearstatcache(true, $path);
        $owner = fileowner($path);

        if (!is_dir($path) || $owner !== posix_geteuid()) {
            throw new \RuntimeException($path.' is not a directory owned by the current user');
        }

        if ((fileperms($path) & 0077) !== 0) {
            // chmod() does not invalidate PHP's stat cache on all PHP versions,
            // so later fileperms() calls could still see the old mode.
            chmod($path, 0700);
            clearstatcache(true, $path);
        }

        return (string) realpath($path);
    }

    /**
     * Validate a strict YYYY-MM-DD date (zero padded, as the downloader expects) and return it unchanged.
     */
    public static function parseDate(string $value): string
    {
        $date = preg_match(self::DATE_RE, $value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('Invalid date %s, expected YYYY-MM-DD', json_encode($value)));
        }

        return $value;
    }

    /**
     * Build the period for the downloader.
     *
     * A date range wins over a symbolic scope; without both the default last_month is used.
     * Only a plain symbolic name or an ISO date range is ever passed on to the downloader.
     */
    public static function buildScope(?string $fromDate, ?string $toDate, ?string $scope): string
    {
        if (($fromDate ?? '') !== '' || ($toDate ?? '') !== '') {
            if (($fromDate ?? '') === '' || ($toDate ?? '') === '') {
                throw new \InvalidArgumentException('Both FROM_DATE and TO_DATE have to be set');
            }

            $start = self::parseDate($fromDate);
            $end = self::parseDate($toDate);

            if ($start > $end) {
                throw new \InvalidArgumentException('FROM_DATE is after TO_DATE');
            }

            return $start.'>'.$end;
        }

        if (($scope ?? '') !== '') {
            if (!preg_match(self::SCOPE_RE, $scope)) {
                throw new \InvalidArgumentException(sprintf('Invalid scope %s', json_encode($scope)));
            }

            return $scope;
        }

        return self::DEFAULT_SCOPE;
    }

    /**
     * MultiFlexi style result document.
     *
     * @param array<string, mixed> $artifacts
     *
     * @return array<string, mixed>
     */
    public static function buildReport(string $status, string $message, array $artifacts = []): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'artifacts' => array_filter($artifacts, static fn ($value): bool => $value !== null),
        ];
    }

    /**
     * Download ABO statements for the given scope.
     *
     * Returns [files, info]; files is null when the download failed and an empty array
     * when the bank has no statement for the period.
     *
     * @param array<string, string> $env
     *
     * @return array{0: null|array<int, string>, 1: array<string, mixed>}
     */
    public function downloadStatements(string $scope, string $outputDir, array $env): array
    {
        try {
            $outputDir = self::ensurePrivateDir($outputDir);
            // Every run gets its own private directory (mode 0700), so statements of other
            // jobs/companies sharing OUTPUT_DIR can never be picked up and imported by mistake.
            do {
                $runDir = $outputDir.'/run-'.bin2hex(random_bytes(6));
            } while (file_exists($runDir));

            if (!mkdir($runDir, 0700)) {
                throw new \RuntimeException('Cannot create '.$runDir);
            }
        } catch (\RuntimeException $exc) {
            return [null, ['error' => $exc->getMessage()]];
        }

        $this->addStatusMessage(sprintf(_('Downloading statements for %s'), $scope), 'info');
        $info = ['scope' => $scope, 'run_dir' => $runDir];
        $info += $this->fetchFormat(self::STATEMENT_FORMAT, $runDir, $scope, $env);

        if (isset($info['error'])) {
            return [null, $info];
        }

        $files = [];

        foreach (glob($runDir.'/*.'.self::STATEMENT_FORMAT) ?: [] as $path) {
            if (is_file($path) && !is_link($path) && \dirname((string) realpath($path)) === (string) realpath($runDir)) {
                $files[] = (string) realpath($path);
            }
        }

        sort($files);
        $info['files'] = $files;

        if ($files === []) {
            @rmdir($runDir); // nothing downloaded, do not leave empty run directories behind
        }

        return [$files, $info];
    }

    /**
     * Run the downloader for one statement format.
     *
     * @param array<string, string> $env
     *
     * @return array<string, mixed> returncode, or error (redacted) when the download failed
     */
    public function fetchFormat(string $format, string $runDir, string $scope, array $env): array
    {
        $env['CSAS_STATEMENT_SCOPE'] = $scope;

        try {
            $result = $this->runProcess([static::DOWNLOADER, '-d'.$runDir, '-f'.$format], $env);
        } catch (\RuntimeException $exc) {
            return ['error' => $exc->getMessage()];
        }

        $info = ['returncode' => $result['code']];

        if ($result['code'] !== 0) {
            $info['error'] = self::redact(substr(trim($result['stderr'] ?: $result['stdout'] ?: 'downloader failed'), -2000), $env);
        }

        return $info;
    }

    /**
     * Import ABO files into Pohoda with pohoda-abo-importer.
     *
     * Returns [report, info]; report is null when no report was produced.
     *
     * @param array<int, string>    $files
     * @param array<string, string> $env
     *
     * @return array{0: null|array<string, mixed>, 1: array<string, mixed>}
     */
    public function importStatements(array $files, array $env): array
    {
        $reportPath = tempnam(sys_get_temp_dir(), 'csas-pohoda-');
        $info = [];

        try {
            $result = $this->runProcess([static::IMPORTER, '-o'.$reportPath, ...$files], $env);
        } catch (\RuntimeException $exc) {
            @unlink($reportPath);

            return [null, ['error' => self::redact($exc->getMessage(), $env)]];
        }

        $info['returncode'] = $result['code'];
        $report = json_decode((string) @file_get_contents($reportPath), true);
        @unlink($reportPath);

        if (!\is_array($report)) {
            $report = null;
            $info['error'] = self::redact(substr(trim($result['stderr'] ?: $result['stdout'] ?: 'importer produced no report'), -2000), $env);
        }

        if ($report === null && $result['code'] !== 0 && !isset($info['error'])) {
            $info['error'] = 'importer failed';
        }

        return [$report, $info];
    }

    /**
     * Download and import; return [exit code, report] with all secret values removed.
     *
     * @param array{from_date?: ?string, to_date?: ?string, scope?: ?string, file?: ?array<int, string>, output_dir?: ?string, env_file?: ?string} $options
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function run(array $options): array
    {
        $env = getenv();

        if (!empty($options['env_file'])) {
            $env = array_merge($env, self::loadEnvFile($options['env_file']));
        }

        [$code, $report] = $this->doRun($options, $env);

        return [$code, self::sanitize($report, $env)];
    }

    /**
     * @param array<string, string> $env
     */
    protected function sharePointClient(array $env): ?GraphSharePointClient
    {
        return GraphSharePointClient::fromEnv($env);
    }

    /**
     * @param array<string, string> $env
     */
    protected function attachments(array $env): ?PohodaAttachments
    {
        return PohodaAttachments::isConfigured($env) ? new PohodaAttachments($env) : null;
    }

    /**
     * Transaction markers (as stored in the internal note by pohoda-abo-importer) of an ABO file.
     *
     * @return array<int, string>
     */
    protected function transactionIds(string $aboFile): array
    {
        $parsed = (new \SpojeNet\AboParser\AboParser())->parseFile($aboFile);

        return array_map(static fn (array $transaction): string => 'ABO_'.$transaction['document_number'].'_'.$transaction['account_number'], $parsed['transactions']);
    }

    /**
     * Run a command without a shell, capturing its output.
     *
     * @param array<int, string>    $command
     * @param array<string, string> $env
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    protected function runProcess(array $command, array $env): array
    {
        if (!is_executable($command[0])) {
            throw new \RuntimeException('Cannot run '.$command[0].': not found or not executable');
        }

        $outFile = tempnam(sys_get_temp_dir(), 'csas-pohoda-out-');
        $errFile = tempnam(sys_get_temp_dir(), 'csas-pohoda-err-');
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);

        if (!\is_resource($process)) {
            @unlink($outFile);
            @unlink($errFile);

            throw new \RuntimeException('Cannot run '.$command[0]);
        }

        $code = proc_close($process);
        $result = ['code' => $code, 'stdout' => (string) file_get_contents($outFile), 'stderr' => (string) file_get_contents($errFile)];
        @unlink($outFile);
        @unlink($errFile);

        return $result;
    }

    /**
     * @param array<string, mixed>  $options
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function doRun(array $options, array $env): array
    {
        try {
            $scope = self::buildScope(
                ($options['from_date'] ?? null) ?: ($env['FROM_DATE'] ?? null) ?: null,
                ($options['to_date'] ?? null) ?: ($env['TO_DATE'] ?? null) ?: null,
                ($options['scope'] ?? null) ?: ($env['CSAS_STATEMENT_SCOPE'] ?? null) ?: null,
            );
        } catch (\InvalidArgumentException $exc) {
            return [self::EXIT_DOWNLOAD_FAILED, self::buildReport('error', $exc->getMessage())];
        }

        $outputDir = ($options['output_dir'] ?? null) ?: ($env['OUTPUT_DIR'] ?? null) ?: self::DEFAULT_OUTPUT_DIR;

        if (!empty($options['file'])) {
            $files = array_map(static fn (string $file): string => (string) (realpath($file) ?: $file), $options['file']);
            $downloadInfo = ['skipped' => 'importing the given files'];

            foreach ($files as $file) {
                if (!is_file($file)) {
                    return [self::EXIT_DOWNLOAD_FAILED, self::buildReport('error', 'File not found: '.$file)];
                }
            }
        } else {
            [$files, $downloadInfo] = $this->downloadStatements($scope, $outputDir, $env);
        }

        if ($files === null) {
            $this->addStatusMessage(_('Failed to download statement(s) from CSAS.'), 'error');

            return [self::EXIT_DOWNLOAD_FAILED, self::buildReport('error', 'Failed to download statement(s) from CSAS.', ['downloader' => $downloadInfo])];
        }

        if ($files === []) {
            $message = sprintf('No statements for %s, nothing to import.', $scope);
            $this->addStatusMessage($message, 'info');

            return [self::EXIT_OK, self::buildReport('success', $message, ['downloader' => $downloadInfo, 'statements' => []])];
        }

        $archive = [];
        $exitCode = self::EXIT_OK;
        $links = [];
        $client = $this->sharePointClient($env);

        if ($client !== null) {
            $pdfs = $this->findPdfs($files, $downloadInfo, $scope, $env);
            [$links, $archive['sharepoint'], $uploaded] = $this->uploadToSharePoint($client, $files, $pdfs, $env);
            $exitCode = $uploaded ? self::EXIT_OK : self::EXIT_SHAREPOINT_FAILED;
        }

        $this->addStatusMessage(sprintf(_('Importing %d statement(s) to Pohoda'), \count($files)), 'info');
        [$importerReport, $importInfo] = $this->importStatements($files, $env);

        if ($importerReport === null || ($importerReport['status'] ?? null) === 'error' || !empty($importInfo['returncode'])) {
            $message = $importerReport['message'] ?? 'Failed to import statement(s) to Pohoda.';
            $this->addStatusMessage($message, 'error');

            return [self::EXIT_IMPORT_FAILED, self::buildReport('error', $message, [
                'statements' => $files,
                'downloader' => $downloadInfo,
                'importer' => $importInfo,
                'importer_report' => $importerReport,
            ] + $archive)];
        }

        $message = $importerReport['message'] ?? 'Statement(s) imported to Pohoda.';

        if ($links !== []) {
            [$archive['attachments'], $attached] = $this->attachLinks($links, $env);

            if (!$attached && $exitCode === self::EXIT_OK) {
                $exitCode = self::EXIT_ATTACH_FAILED;
            }
        }

        $status = $importerReport['status'] ?? 'success';

        if ($exitCode !== self::EXIT_OK) {
            $status = 'error';
            $message .= $exitCode === self::EXIT_SHAREPOINT_FAILED ? ' (uploading to SharePoint failed)' : ' (attaching links to Pohoda failed)';
            $this->addStatusMessage($message, 'error');
        } else {
            $this->addStatusMessage($message, 'success');
        }

        return [$exitCode, self::buildReport($status, $message, [
            'statements' => $files,
            'downloader' => $downloadInfo,
            'importer_report' => $importerReport,
        ] + $archive)];
    }

    /**
     * PDF statement for every ABO file: downloaded next to them or found beside the given files.
     *
     * @param array<int, string>    $files        ABO files
     * @param array<string, mixed>  $downloadInfo
     * @param array<string, string> $env
     *
     * @return array<string, string> ABO file => PDF file
     */
    private function findPdfs(array $files, array $downloadInfo, string $scope, array $env): array
    {
        if (isset($downloadInfo['run_dir'])) {
            $info = $this->fetchFormat('pdf', $downloadInfo['run_dir'], $scope, $env);

            if (isset($info['error'])) {
                $this->addStatusMessage(sprintf(_('PDF statements download failed: %s'), $info['error']), 'error');
            }
        }

        $pdfs = [];

        foreach ($files as $file) {
            $pdf = preg_replace('/\.[^.\/]*$/', '', $file).'.pdf';

            if (is_file($pdf) && !is_link($pdf)) {
                $pdfs[$file] = $pdf;
            }
        }

        return $pdfs;
    }

    /**
     * Upload the ABO and PDF statements to SharePoint.
     *
     * @param array<int, string>    $files ABO files
     * @param array<string, string> $pdfs  ABO file => PDF file
     * @param array<string, string> $env
     *
     * @return array{0: array<string, array{url: string, name: string}>, 1: array<string, mixed>, 2: bool} links of the PDFs by ABO file, report, success
     */
    private function uploadToSharePoint(GraphSharePointClient $client, array $files, array $pdfs, array $env): array
    {
        $permanent = ($env['SHAREPOINT_PERMANENT_LINK'] ?? 'true') !== 'false';
        $linkType = ($env['SHAREPOINT_LINK_TYPE'] ?? '') ?: 'view';
        $linkScope = ($env['SHAREPOINT_LINK_SCOPE'] ?? '') ?: 'organization';
        $pdfPath = $env['OFFICE365_PATH'];
        $aboPath = ($env['OFFICE365_PATH_ABO'] ?? '') ?: $pdfPath;
        $links = [];
        $report = ['abo' => [], 'pdf' => []];
        $success = true;
        $upload = function (string $kind, string $file, string $path) use ($client, $permanent, $linkType, $linkScope, $env, &$report, &$success): ?string {
            $name = basename($file);

            try {
                $url = $client->uploadAndLink($path, $name, (string) file_get_contents($file), $permanent, $linkType, $linkScope);
                $this->addStatusMessage(_('Uploaded').': '.$url, 'success');
                $report[$kind][$name] = $url;

                return $url;
            } catch (\Throwable $exc) {
                $error = self::redact(sprintf('SharePoint upload of %s failed: %s', $name, $exc->getMessage()), $env);
                $this->addStatusMessage($error, 'error');
                $report[$kind][$name] = ['error' => $error, 'httpCode' => $exc->getCode()];
                $success = false;

                return null;
            }
        };

        foreach ($files as $file) {
            $upload('abo', $file, $aboPath);

            if (isset($pdfs[$file])) {
                $url = $upload('pdf', $pdfs[$file], $pdfPath);

                if ($url !== null) {
                    $links[$file] = ['url' => $url, 'name' => basename($pdfs[$file])];
                }
            } else {
                $this->addStatusMessage(sprintf(_('No PDF statement for %s'), basename($file)), 'warning');
                $report['pdf'][basename($file)] = ['error' => 'PDF statement not available'];
                $success = false;
            }
        }

        return [$links, $report, $success];
    }

    /**
     * Attach the PDF links to the Pohoda bank records of each statement.
     *
     * @param array<string, array{url: string, name: string}> $links ABO file => PDF link
     * @param array<string, string>                           $env
     *
     * @return array{0: array<string, mixed>, 1: bool} report, success
     */
    private function attachLinks(array $links, array $env): array
    {
        $attachments = $this->attachments($env);

        if ($attachments === null) {
            $this->addStatusMessage(_('Pohoda SQL Server is not configured (DB_HOST, DB_DATABASE); skipping attachments'), 'info');

            return [['skipped' => 'DB_HOST and DB_DATABASE are not set'], true];
        }

        $report = [];
        $success = true;

        foreach ($links as $file => $link) {
            $name = basename($file);

            try {
                $transactions = $this->transactionIds($file);
                $records = $attachments->findBankRecordIds($transactions);
                $result = ['transactions' => \count($transactions), 'records' => \count($records), 'attached' => 0, 'existing' => 0, 'failed' => 0];

                foreach ($records as $recordId) {
                    if ($attachments->hasUrlAttachment($recordId, $link['url'])) {
                        ++$result['existing'];
                    } elseif ($attachments->addUrlAttachment($recordId, $link['url'], $link['name'])) {
                        ++$result['attached'];
                    } else {
                        ++$result['failed'];
                    }
                }

                if ($result['failed'] > 0) {
                    $success = false;
                }

                $this->addStatusMessage(sprintf(_('%s: link attached to %d of %d bank record(s)'), $name, $result['attached'] + $result['existing'], $result['records']), $result['failed'] ? 'error' : 'success');
            } catch (\Throwable $exc) {
                $result = ['error' => self::redact($exc->getMessage(), $env)];
                $this->addStatusMessage(sprintf('Attaching link for %s failed: %s', $name, $result['error']), 'error');
                $success = false;
            }

            $report[$name] = $result;
        }

        return [$report, $success];
    }
}
