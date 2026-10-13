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

use Ease\Shared;

\define('APP_NAME', 'CSAS Statement to Pohoda Importer');

require_once '../vendor/autoload.php';

$options = getopt('o:e:h', ['output:', 'environment:', 'from-date:', 'to-date:', 'scope:', 'file:', 'output-dir:', 'help']);

if (\array_key_exists('h', $options) || \array_key_exists('help', $options)) {
    echo "Download CSAS statements and import them to Pohoda.\n\n";
    echo "Usage: csas-pohoda [options]\n\n";
    echo "  --from-date <YYYY-MM-DD>  Start date (env FROM_DATE)\n";
    echo "  --to-date <YYYY-MM-DD>    End date (env TO_DATE)\n";
    echo "  --scope <name>            Symbolic period, e.g. last_month, yesterday (env CSAS_STATEMENT_SCOPE)\n";
    echo "  --file <path>             Import this already downloaded ABO file instead of downloading (repeatable)\n";
    echo '  --output-dir <dir>        Directory for statements (env OUTPUT_DIR, default '.StatementSync::DEFAULT_OUTPUT_DIR.")\n";
    echo "  -e, --environment <file>  Optional .env file with the configuration\n";
    echo "  -o, --output <file>       Write the JSON report to this file (env RESULT_FILE, default stdout)\n";

    exit(0);
}

$option = static fn (string $long, string $short = ''): mixed => $options[$long] ?? ($short !== '' ? ($options[$short] ?? null) : null);

$envFile = $option('environment', 'e');

if (\is_string($envFile) && is_file($envFile)) {
    Shared::init([], $envFile);
}

$engine = new StatementSync();
$engine->logBanner();

try {
    [$exitcode, $report] = $engine->run([
        'from_date' => $option('from-date'),
        'to_date' => $option('to-date'),
        'scope' => $option('scope'),
        'file' => $option('file') === null ? null : (array) $option('file'),
        'output_dir' => $option('output-dir'),
        'env_file' => \is_string($envFile) ? $envFile : null,
    ]);
} catch (\Throwable $exc) { // the job must never end silently
    $env = getenv();
    $engine->addStatusMessage(sprintf('Unexpected error: %s: %s', $exc::class, $exc->getMessage()), 'error');
    $report = StatementSync::sanitize(StatementSync::buildReport('error', sprintf('Unexpected error: %s: %s', $exc::class, $exc->getMessage())), $env);
    $report['artifacts']['traceback'] = StatementSync::redact(substr($exc->getTraceAsString(), -4000), $env);
    $exitcode = StatementSync::EXIT_DOWNLOAD_FAILED;
}

if (empty($report['artifacts'])) {
    $report['artifacts'] = new \stdClass(); // a JSON object, not []
}

$document = json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n";
$destination = $option('output', 'o') ?? Shared::cfg('RESULT_FILE', getenv('RESULT_FILE') ?: 'php://stdout');

if (\is_string($destination) && $destination !== '' && $destination !== '-' && $destination !== 'php://stdout') {
    if (@file_put_contents($destination, $document) === false) {
        $engine->addStatusMessage(sprintf(_('Cannot write report to %s'), $destination), 'error');
        echo $document;
        $exitcode = $exitcode ?: StatementSync::EXIT_DOWNLOAD_FAILED;
    } else {
        $engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), 'success');
    }
} else {
    echo $document;
}

exit($exitcode);
