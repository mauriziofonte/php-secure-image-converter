<?php

declare(strict_types=1);

/*
 * Entry point of the GD worker, started by the GdBackend as "php -n ... gd.php probe|convert" in a job's
 * working directory. It loads the worker classes directly, without Composer: see
 * Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker.
 */

require __DIR__ . '/../../src/Backend/Worker/AbstractWorker.php';
require __DIR__ . '/../../src/Backend/Worker/GdWorker.php';

exit(Mfonte\SecureImageConverter\Backend\Worker\GdWorker::main($argv));
