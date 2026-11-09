# Configuration reference

This page lists every setting of `mfonte/secure-image-converter`, with its default and its accepted range, as defined
in the code. Every option object is immutable: each `with*()` method validates its argument, returns a modified copy
and throws `InvalidArgumentException` (`internal_error`) for a value out of range. Paths are always absolute and
normalized. See the [README](../README.md) for an overview.

- [SecureConverterBuilder](#secureconverterbuilder)
- [Limits](#limits)
- [ConverterOptions](#converteroptions)
- [ProcessingBudget](#processingbudget)
- [BackendOptions](#backendoptions)
- [IsolationPolicy](#isolationpolicy)
- [IsolationDetector](#isolationdetector)
- [ProcessOptions](#processoptions)
- [ResourceLimits](#resourcelimits)
- [Semaphore, KeyedLock and Backoff](#semaphore-keyedlock-and-backoff)
- [Putting it together](#putting-it-together)

## SecureConverterBuilder

`Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder` creates a `SecureConverter`. `build()` can be called
any number of times. It detects the isolation level, applies the policy, and creates the runner, the backends and
the inspector.

| Method                    | Default                                                                         |
| ------------------------- | ------------------------------------------------------------------------------- |
| `withLimits()`            | `new Limits()`                                                                  |
| `withInputFormats()`      | `ImageInspector::DEFAULT_INPUT_FORMATS`: `jpeg`, `png`, `gif`, `bmp`            |
| `withIsolationPolicy()`   | `IsolationPolicy::bestEffort()`, expecting `sandbox`                            |
| `withIsolationDetector()` | `new IsolationDetector($processOptions)`                                        |
| `withProcessOptions()`    | `new ProcessOptions()`                                                          |
| `withBackendOptions()`    | `new BackendOptions()`                                                          |
| `withBackends()`          | every name of `BackendFactory::NAMES`: `vips`, `cwebp`, `gif2webp`, `avifenc`, `imagick`, `gd` |
| `withSemaphore()`         | none                                                                            |
| `withConverterOptions()`  | `new ConverterOptions()`                                                        |

`build()` throws `IsolationUnavailableException` when a strict policy requires a level the host does not provide,
and `InvalidArgumentException` for an unknown backend or input format. When the converter options name a working
directory parent and the backend options do not, the backends use it too.

`withInputFormats()` accepts `ImageFormat` values. SVG may be listed but is always refused. An empty list refuses
every input.

## Limits

`Mfonte\SecureImageConverter\Validation\Limits`. Every value is an integer from 1 to
`Capabilities::LIMIT_VALUE_MAX` (2147483647).

| Name                   | Method                      | Default    | Meaning                                         |
| ---------------------- | --------------------------- | ---------- | ----------------------------------------------- |
| `max_bytes`            | `withMaxBytes()`            | `20971520` | input file size in bytes (20 MiB)               |
| `max_width`            | `withMaxWidth()`            | `8192`     | width in pixels, input and resolved output      |
| `max_height`           | `withMaxHeight()`           | `8192`     | height in pixels, input and resolved output     |
| `max_megapixels`       | `withMaxMegapixels()`       | `40`       | area of one frame, in millions of pixels        |
| `max_frames`           | `withMaxFrames()`           | `300`      | number of frames                                |
| `max_total_megapixels` | `withMaxTotalMegapixels()`  | `400`      | area of all frames together, in millions of pixels |

`Limits::fromArray(['max_bytes' => 10485760])` reads limits from configuration, keyed by these names. A missing name
keeps its default, and an unknown name or a non-integer value is rejected. `withLimit($name, $value)` sets one limit
by name. Whatever the limits, a resolved output side is never above 16383 pixels.

## ConverterOptions

`Mfonte\SecureImageConverter\Pipeline\ConverterOptions`.

| Method                         | Default              | Range                         | Meaning                                              |
| ------------------------------ | -------------------- | ----------------------------- | ---------------------------------------------------- |
| `withWorkingDirectoryParent()` | `null` (`sys_get_temp_dir()`) | an existing directory, or `null` | where each conversion creates its private directory (mode `0700`) |
| `withSlotWaitSeconds()`        | `30.0`               | 0 (one attempt) to 86400      | longest wait for a semaphore slot                    |
| `withMaxOutputBytes()`         | `268435456` (256 MiB) | 1 to 1 TiB                    | largest output accepted; keep it at or below the file size limit of the runner |
| `withBudget()`                 | `new ProcessingBudget()` |                           | timeout and CPU time of each job                     |

`withIsolation()` is set by the builder from the isolation decision: the level that `getCapabilities()` reports and
the warnings added to every result.

## ProcessingBudget

`Mfonte\SecureImageConverter\Pipeline\ProcessingBudget` derives each job's wall-clock timeout and CPU time limit from
its work (see [resource-limits.md](resource-limits.md#time-and-cpu)).

| Method             | Default  | Range                              | Meaning                                                  |
| ------------------ | -------- | ---------------------------------- | -------------------------------------------------------- |
| `withMaxSeconds()` | `3600.0` | the floor to 86400                 | ceiling of a job's timeout; a job estimated above it is refused |
| `withMinSeconds()` | `30.0`   | above 0, at most the ceiling       | floor of a job's timeout                                 |
| `withScale()`      | `1.0`    | 0.1 to 100                         | factor applied to every estimate; above 1 for a slower host |

The CPU time limit is `ProcessingBudget::CPU_FACTOR` (2) times the timeout, at most 86400 seconds.

## BackendOptions

`Mfonte\SecureImageConverter\Backend\BackendOptions`.

| Method                              | Default                                  | Range                     | Meaning |
| ----------------------------------- | ---------------------------------------- | ------------------------- | ------- |
| `withMemoryCeilingBytes()`          | `2147483648` (2 GiB)                     | 16 MiB to 1 TiB           | largest memory estimate admitted, and the address space limit of every backend process at the `rlimit` and `sandbox` levels |
| `withWorkingDirectoryParent()`      | `null` (`sys_get_temp_dir()`)            | a directory, or `null`    | where each job's private directory is created |
| `withPhpBinary()`                   | `null`: the running PHP when it is the CLI, otherwise `php` from the search path | a PHP CLI binary, or `null` | PHP that runs the GD and Imagick workers |
| `withPhpExtensionDirectory()`       | `null`: the binary's built-in directory  | a directory, or `null`    | where the workers load `gd.so` and `imagick.so` from |
| `withCapabilityCache($enabled, $directory)` | enabled, `sys_get_temp_dir()/secure-image-converter-<euid>` | a private directory, or `null` | where probed capabilities are kept |
| `withCapabilityCacheTtlSeconds()`   | `86400` (one day)                        | 1 to 604800 (7 days)      | age after which capabilities are probed again |
| `withLogger()`                      | `NullLogger`                             | any PSR-3 logger          | receives the backends' warnings, such as an unusable capability cache |

Notes:

- The workers run `php -n`, which reads no `php.ini`, so an `extension_dir` set there does not apply. A configured
  extension directory is used only when it holds `<extension>.so`, and the directory, the file and every directory
  above them are owned by root or the effective user and not writable by others (a sticky directory such as `/tmp`
  is accepted above them). Otherwise the backend is unavailable.
- The capability cache directory must be owned by the effective user and not writable by group or others. It is
  created with mode `0700` when missing. A directory that fails the checks is ignored, a warning is logged, and every
  process probes again.

## IsolationPolicy

`Mfonte\SecureImageConverter\Isolation\IsolationPolicy`.

| Factory                               | Effect                                                                         |
| ------------------------------------- | ------------------------------------------------------------------------------ |
| `IsolationPolicy::strict($level)`     | `build()` throws `IsolationUnavailableException` when the host provides less than `$level` |
| `IsolationPolicy::bestEffort($level)` | conversions run at the strongest level available, with a warning in every result while it is below `$level`; `$level` defaults to `sandbox` |
| `IsolationPolicy::create($mode, $level)` | `$mode` is `strict` or `best_effort`, for values read from configuration    |

Levels are the `IsolationLevel` constants: `none`, `process`, `rlimit`, `sandbox`.

## IsolationDetector

`new IsolationDetector($options, $cacheDirectory, $cacheTtlSeconds, $logger)`:

| Argument           | Default                                              | Meaning |
| ------------------ | ---------------------------------------------------- | ------- |
| `$options`         | `new ProcessOptions()`                               | the process options the conversions will run with; a level is reported only if it works with them |
| `$cacheDirectory`  | `null`: `sys_get_temp_dir()/secure-image-converter-<euid>` | directory of the file cache, created with mode `0700` when missing |
| `$cacheTtlSeconds` | `3600`                                               | from 0 (no file cache) to 604800 |
| `$logger`          | `NullLogger`                                         | receives the detected level (info) and the probe details (debug) |

`detect()` returns the cached result when there is one; `refresh()` probes again. Pass the same `ProcessOptions` to
the detector and to the builder. The builder gives the detector to the converter, which calls `refresh()` after a
conversion fails with `conversion_failed` while the detection is at least `SecureConverter::ISOLATION_RECHECK_SECONDS`
(60) old, so that a cached level that stopped working is replaced for the next converter built.

`getCachedResults()` returns the unexpired results (`list<DetectionResult>`, each `isFromCache()`) cached in the
detector's directory for every configuration: each PHP SAPI, effective user and set of process options has its own
entry. It probes, creates and writes nothing; it returns an empty list when the TTL is 0, the `posix` extension is
missing, or the directory is missing or not private, and leaves out every file that `detect()` would not trust,
including entries older than this detector's TTL.

## ProcessOptions

`Mfonte\SecureImageConverter\Isolation\ProcessOptions` controls how every program is started.

| Method                         | Default                             | Range / form                         | Meaning |
| ------------------------------ | ----------------------------------- | ------------------------------------ | ------- |
| `withTimeoutSeconds()`         | `30.0`                              | above 0, at most 86400               | wall-clock timeout of a run; backend jobs use the processing budget instead |
| `withKillGraceSeconds()`       | `1.0`                               | 0 to 60                              | delay between `SIGTERM` and `SIGKILL` after a timeout |
| `withMaxOutputBytes()`         | `1048576` (1 MiB)                   | 0 to 256 MiB                         | bytes of stdout, and of stderr, kept |
| `withResourceLimits()`         | `new ResourceLimits()`              |                                      | `prlimit` limits at the `rlimit` and `sandbox` levels |
| `withEnvironment()`            | `LANG=C`, `LC_ALL=C`                | name => value                        | variables set explicitly (replaces the defaults) |
| `withInheritedEnvironment()`   | none                                | list of names                        | variables copied from the PHP process when set |
| `withSearchPath()`             | `/usr/local/bin`, `/usr/bin`, `/bin`, `/usr/local/sbin`, `/usr/sbin`, `/sbin` | absolute directories | where programs and tools are looked up; also `PATH` |
| `withSandboxReadOnlyPaths()`   | `/usr`, `/bin`, `/sbin`, `/lib`, `/lib32`, `/lib64`, `/libx32`, `/etc/alternatives`, `/etc/ld.so.cache`, `/etc/ld.so.conf`, `/etc/ld.so.conf.d` | absolute paths | host paths visible, read-only, in the sandbox (replaces the defaults) |
| `withSandboxHiddenPaths()`     | `/usr/local/etc`, `/usr/local/src`, `/usr/src` | absolute paths            | directories shown empty in the sandbox (replaces the defaults) |
| `withToolPath($tool, $path)`   | looked up in the search path        | `TOOL_SETSID`, `TOOL_PRLIMIT`, `TOOL_BWRAP`; `null` disables | where `setsid`, `prlimit` and `bwrap` are |

`PATH`, `HOME`, `TMPDIR` and `PWD` are always set by the runner and cannot be overridden. To extend a default list,
pass it together with your additions, for example
`withSandboxHiddenPaths(array_merge(ProcessOptions::DEFAULT_SANDBOX_HIDDEN_PATHS, ['/usr/local/secrets']))`.

## ResourceLimits

`Mfonte\SecureImageConverter\Isolation\ResourceLimits` holds the `prlimit` limits applied at the `rlimit` and
`sandbox` levels. Each is set as both the soft and the hard limit.

| Method                    | Default                 | Range            | Notes |
| ------------------------- | ----------------------- | ---------------- | ----- |
| `withAddressSpaceBytes()` | `1073741824` (1 GiB)    | 16 MiB to 1 TiB  | replaced by the memory ceiling for backend jobs |
| `withCpuSeconds()`        | `60`                    | 1 to 86400       | replaced by the job's CPU time limit for backend jobs |
| `withFileSizeBytes()`     | `268435456` (256 MiB)   | 1 to 1 TiB       | applies to backend jobs unchanged |
| `withOpenFiles()`         | `128`                   | 8 to 1048576     | caps descriptor numbers; raise it if PHP keeps many descriptors open |
| `withProcesses()`         | `null` (disabled)       | 1 to 1048576, or `null` | the kernel counts every process of the user; enable only for a dedicated user |

The core dump size is always 0 and cannot be changed.

## Semaphore, KeyedLock and Backoff

| Constructor                                         | Constraints |
| --------------------------------------------------- | ----------- |
| `new LockDirectory($path)`                          | a private directory of the effective user; see [concurrency.md](concurrency.md) |
| `new Semaphore($directory, $name, $slots, $backoff)` | `$name` of 1 to 64 letters, digits, `-` or `_`; `$slots` from 1 to 256 |
| `new KeyedLock($directory, $name, $backoff)`        | `$name` as above; any string is a valid key |
| `new Backoff($initialDelay, $maxDelay)`             | defaults 0.002 and 0.05 seconds; delays from 0.00001 to 5 seconds |

`acquire()` timeouts range from 0 to 86400 seconds.

## Putting it together

```php
use Mfonte\SecureImageConverter\Backend\BackendFactory;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\Semaphore;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\IsolationDetector;
use Mfonte\SecureImageConverter\Isolation\IsolationPolicy;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\ProcessingBudget;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Mfonte\SecureImageConverter\Validation\Limits;

$processOptions = new ProcessOptions();

$converter = (new SecureConverterBuilder())
    ->withLimits(Limits::fromArray(['max_bytes' => 10485760, 'max_megapixels' => 24]))
    ->withInputFormats([ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP])
    ->withIsolationPolicy(IsolationPolicy::strict(IsolationLevel::SANDBOX))
    ->withProcessOptions($processOptions)
    ->withIsolationDetector(new IsolationDetector($processOptions, '/srv/app/var/cache/image-converter'))
    ->withBackends(BackendFactory::parseNames('vips,cwebp,gif2webp,avifenc'))
    ->withBackendOptions(
        (new BackendOptions())
            ->withMemoryCeilingBytes(1536 * 1024 * 1024)
            ->withCapabilityCache(true, '/srv/app/var/cache/image-converter')
    )
    ->withSemaphore(new Semaphore(new LockDirectory('/run/image-converter/locks'), 'convert', 4))
    ->withConverterOptions(
        (new ConverterOptions())
            ->withWorkingDirectoryParent('/srv/app/var/tmp')
            ->withSlotWaitSeconds(10.0)
            ->withBudget((new ProcessingBudget())->withMaxSeconds(600.0))
    )
    ->build();
```
