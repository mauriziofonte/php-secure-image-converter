<?php

declare(strict_types=1);

use Mfonte\SecureImageConverter\Tests\Fixtures\Concurrency\ConcurrencyWorker;

/*
 * Worker process of the concurrency integration tests: php worker.php <JSON options>. See ConcurrencyWorker.
 *
 * tests/bootstrap.php is loaded first, so every notice or warning raised by the library in the worker is an
 * exception and makes the worker fail.
 */
require dirname(__DIR__, 2) . '/bootstrap.php';

exit(ConcurrencyWorker::main($argv));
