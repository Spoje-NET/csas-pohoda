<?php

declare(strict_types=1);

/**
 * Debian autoloader for csas-pohoda
 */

require_once '/usr/share/php/Ease/autoload.php';
require_once '/usr/share/php/SpojeNet/AboParser/autoload.php';
require_once '/usr/share/php/PohodaSQL/autoload.php';
require_once '/usr/share/php/Office365/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'SpojeNet\\CsasPohoda\\' => '/usr/lib/csas-pohoda/CsasPohoda/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);

        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $file = $baseDir . str_replace('\\', '/', substr($class, $len)) . '.php';

        if (file_exists($file)) {
            require $file;

            return;
        }
    }
});
