<?php

declare(strict_types=1);

use Mfonte\SecureImageConverter\Tests\StrictErrorHandler;

/*
 * PHPUnit bootstrap. Every notice, warning and deprecation raised from src/ or tests/ becomes an exception, so PHP
 * 8.4/8.5 deprecations fail the build; deprecations raised inside vendor/ are reported but not fatal. The handler
 * is installed before the autoloader so that it also covers code run while Composer's autoload files are loaded.
 */
error_reporting(E_ALL);

$projectRoot = dirname(__DIR__);

if (!is_file($projectRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, 'Dependencies are missing. Run: composer install' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/StrictErrorHandler.php';

StrictErrorHandler::install([$projectRoot . '/src', $projectRoot . '/tests']);

require_once $projectRoot . '/vendor/autoload.php';
