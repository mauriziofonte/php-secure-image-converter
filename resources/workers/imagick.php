<?php

declare(strict_types=1);

/*
 * Entry point of the Imagick worker, started by the ImagickBackend as "php -n ... imagick.php probe|convert" in a job's
 * working directory. It loads the worker classes directly, without Composer: see
 * Mfonte\SecureImageConverter\Backend\Worker\AbstractWorker.
 */

require __DIR__ . '/../../src/Backend/Worker/AbstractWorker.php';
require __DIR__ . '/../../src/Backend/Worker/ImagickWorker.php';

exit(Mfonte\SecureImageConverter\Backend\Worker\ImagickWorker::main($argv));
