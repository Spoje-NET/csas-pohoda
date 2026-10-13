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

if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
} else {
    // Debian packages (php-vitexsoftware-ease-core) when composer install was not run
    require_once '/usr/share/php/Ease/autoload.php';
    spl_autoload_register(static function (string $class): void {
        $prefix = 'SpojeNet\\CsasPohoda\\';

        if (str_starts_with($class, $prefix)) {
            require __DIR__.'/../src/CsasPohoda/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
        }
    });
}

\define('APP_NAME', 'CsasPohodaTests');
\Ease\Shared::instanced()->setConfigValue('EASE_LOGGER', 'none');
