# Secure Image Converter

A PHP library that turns untrusted image files into web-optimized WEBP and AVIF files. Every input is identified
from its content and checked against size, dimension, pixel and frame limits before any decoder sees it. Decoding
and encoding run in separate processes, under kernel resource limits and, where the host allows it, inside a
bubblewrap sandbox. Every output is inspected again before it is returned.

[![CI](https://github.com/mauriziofonte/php-secure-image-converter/actions/workflows/ci.yml/badge.svg)](https://github.com/mauriziofonte/php-secure-image-converter/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-7.2.5%20to%208.5-777bb4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE)

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [How a conversion works](#how-a-conversion-works)
- [Conversion requests](#conversion-requests)
- [Conversion results](#conversion-results)
- [Formats](#formats)
- [Backends](#backends)
- [Input validation](#input-validation)
- [Isolation](#isolation)
- [Resource limits](#resource-limits)
- [Concurrency](#concurrency)
- [Error handling](#error-handling)
- [Configuration](#configuration)
- [Deployment and operations](#deployment-and-operations)
- [Performance](#performance)
- [Testing and development](#testing-and-development)
- [Versioning and compatibility](#versioning-and-compatibility)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

## Features

- **One small contract.** Callers depend on `ConverterInterface`, an immutable `ConversionRequest` and an immutable
  `ConversionResult`. Every failure is an exception with a stable, machine-readable error code.
- **WEBP and AVIF output**, or re-encoding in the input format. Resizing supports `contain`, `cover` and `fill`,
  with or without enlargement. Metadata is stripped by default, while the ICC profile is kept. Animations can be
  preserved, reduced to their first frame, or rejected.
- **Content-based format detection.** The format comes from the magic bytes, never from a file name, an extension
  or a declared MIME type. JPEG, PNG, GIF and BMP are enabled by default; WEBP, AVIF and TIFF are opt-in. SVG is
  identified and always refused.
- **Header validation without decoding.** A bounded parser for each format reads dimensions, frame counts and
  metadata markers, and refuses dimension, decompression and frame bombs after a few bytes.
- **Six backends**, chosen per job in a fixed order of preference: libvips, cwebp, gif2webp, avifenc, and PHP
  workers for Imagick and GD. What each backend can do is proved by converting probe images, never read from
  version numbers. The result is cached.
- **Four isolation levels** (`none`, `process`, `rlimit`, `sandbox`), detected at run time. A strict or best-effort
  policy decides what happens when the host provides less than you asked for.
- **Resource budgets.** A memory ceiling per process, with an admission estimate built from measured peaks. Each
  job gets a wall-clock timeout and a CPU time limit derived from its work, and the file size and open file limits
  of `prlimit` apply.
- **Output verification.** The produced file is parsed again. Its format, dimensions, frame count, metadata and
  trailing bytes must match the job before it is published atomically at the output path.
- **Concurrency primitives** on `flock()`: a counting `Semaphore` that bounds the conversions running at once, and a
  per-key `KeyedLock` for single-flight work.
- **Wide PHP support:** PHP 7.2.5 to 8.5 on 64-bit builds, tested in CI on every minor version in that range. The
  only runtime dependency is `psr/log`.

## Requirements

**PHP**

- PHP 7.2.5 or later (7.x or 8.x), 64-bit. `composer.json` requires `php-64bit`.
- Extensions: `hash` and `json`. `posix` is strongly recommended. Without it, the `process`, `rlimit` and `sandbox`
  levels are unavailable, and the private cache directories cannot be verified, so they are not used.
- `proc_open()` must not be disabled. Every backend runs as a separate process.
- `psr/log` 1.1, 2.x or 3.x.

**Operating system**

The isolation levels rely on Linux tools from util-linux and bubblewrap, and the lock files need a POSIX system.
The isolation tools are:

| Level     | Needs                                                                    | Ubuntu / Debian package |
| --------- | ------------------------------------------------------------------------ | ----------------------- |
| `process` | `setsid`, the `posix` extension                                          | `util-linux`            |
| `rlimit`  | `setsid`, `prlimit`                                                      | `util-linux`            |
| `sandbox` | `setsid`, `prlimit`, `bwrap`, and unprivileged user namespaces           | `bubblewrap`            |

**Backends** (at least one is needed; install the ones you want):

| Backend    | Program                         | Ubuntu / Debian package                       |
| ---------- | ------------------------------- | --------------------------------------------- |
| `vips`     | `vips`                          | `libvips-tools` (and `libheif-plugin-aomenc` for AVIF output) |
| `cwebp`    | `cwebp`                         | `webp`                                        |
| `gif2webp` | `gif2webp`                      | `webp`                                        |
| `avifenc`  | `avifenc`                       | `libavif-bin`                                 |
| `imagick`  | PHP CLI with `ext-imagick`      | `imagemagick`, `php-imagick`                  |
| `gd`       | PHP CLI with `ext-gd`           | `php-gd`                                      |

These are the packages that the CI installs on Ubuntu 24.04. The reference test image in
[`tests/docker/Dockerfile`](tests/docker/Dockerfile) holds all of them.

## Installation

The library is not published on Packagist. Install it from its Git repository through a Composer
[VCS repository](https://getcomposer.org/doc/05-repositories.md#vcs), and pin a release tag:

```bash
composer config repositories.secure-image-converter vcs https://github.com/mauriziofonte/php-secure-image-converter
composer require mfonte/secure-image-converter:^1.0
```

or, in `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/mauriziofonte/php-secure-image-converter"
        }
    ],
    "require": {
        "mfonte/secure-image-converter": "^1.0"
    }
}
```

To pin one exact release, use its tag as the constraint, for example `"1.0.0"`. Archives built by Composer leave out
the tests and the development tooling (`.gitattributes`).

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;

$converter = (new SecureConverterBuilder())->build();

$request = (new ConversionRequest(ImageFormat::WEBP))
    ->withQuality(80)
    ->withWidth(1200);

try {
    $result = $converter->convert('/path/to/upload.jpg', '/path/to/output.webp', $request);

    printf(
        "%s %dx%d, %d bytes, backend %s, isolation %s\n",
        $result->getMimeType(),
        $result->getWidth(),
        $result->getHeight(),
        $result->getByteSize(),
        $result->getBackend(),
        $result->getIsolationLevel()
    );
    foreach ($result->getWarnings() as $warning) {
        error_log($warning);
    }
} catch (ExceptionInterface $exception) {
    // Decide on the stable code, never on the message.
    error_log($exception->getErrorCode() . ': ' . $exception->getMessage());
}
```

`build()` detects the strongest isolation level of the host and applies the default best-effort policy: it expects
`sandbox` and reports a warning in every result when the host provides less. The first conversion probes the
installed backends, which takes seconds. Later processes read the result from the capability cache.

`getCapabilities()` reports what the converter can do on this host, as a value object or as an array:

```php
echo json_encode($converter->getCapabilities()->toArray(), JSON_PRETTY_PRINT);
```

```json
{
    "schema": 1,
    "input_formats": ["jpeg", "png", "gif", "bmp"],
    "output_formats": ["jpeg", "png", "gif", "bmp", "webp", "avif", "tiff"],
    "animated_output_formats": ["gif", "webp"],
    "backends": ["vips", "cwebp", "gif2webp", "avifenc", "imagick", "gd"],
    "isolation_level": "sandbox",
    "limits": {
        "max_bytes": 20971520,
        "max_width": 8192,
        "max_height": 8192,
        "max_megapixels": 40,
        "max_frames": 300,
        "max_total_megapixels": 400
    }
}
```

The output depends on the host. `Capabilities::fromArray()` reads the array back strictly: any other schema version,
or any missing or unknown key, is rejected.

## How a conversion works

`SecureConverter::convert()` runs these steps. A failure at any step throws, and leaves nothing at the output path.

1. **Private copy.** The converter checks both paths (local paths only, no URLs or stream wrappers), creates a
   private working directory (mode `0700`) and copies the input into it. It reads at most `max_bytes + 1` bytes,
   from a descriptor that must be a regular file. Every later step reads this copy only, so the caller's file is
   never opened again.
2. **Inspection.** `ImageInspector` identifies the format from its content and reads its header. It checks the
   file size, dimensions, pixels and frames against the limits. No decoder runs.
3. **Planning.** The request is resolved against the input: the output format, the exact output size (the EXIF
   orientation is applied, so orientations 5 to 8 swap width and height) and the output frame count. The resolved
   output is checked against the same limits before any encoder runs.
4. **Slot.** When a semaphore is configured, the converter takes a slot, or throws `concurrency_limit` after the
   configured wait.
5. **Backend selection.** The first backend that can perform the job, under the memory ceiling, is chosen (see
   [Backends](#backends)). When the requested format cannot be produced, the converter falls back from AVIF to WEBP
   to the input format, and says so in a warning.
6. **Time budget.** The job's timeout and CPU time limit are derived from its work. A job estimated above the
   processing ceiling is refused with `conversion_timeout` before it starts.
7. **Conversion.** The backend runs in its own working directory at the detected isolation level.
8. **Verification.** The output is parsed again. Its format, size, frame count, metadata and trailing data must
   match the job, and it must fit the output limits.
9. **Keep the smaller file.** When the output is not smaller than the input, and the input already satisfies the
   whole request, the input bytes are returned unchanged. The result reports a passthrough, with a warning.
10. **Publication.** The chosen file is copied next to the destination, checked again and renamed over it. The
    working directory is removed, whatever the outcome.

## Conversion requests

`ConversionRequest` is an immutable value object. Every `with*()` method validates its argument and returns a
modified copy. An invalid value throws `InvalidRequestException` (`invalid_request`).

```php
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ImageFormat;

$thumbnail = (new ConversionRequest(ImageFormat::AVIF))
    ->withWidth(400)
    ->withHeight(400)
    ->withFit(ConversionRequest::FIT_COVER)
    ->withEffort(6)
    ->withAnimationPolicy(ConversionRequest::ANIMATION_FIRST_FRAME);
```

The same parameters have a canonical string form, for HTTP query strings and form bodies:

| Name        | Values                                         | Default    | Method                       |
| ----------- | ---------------------------------------------- | ---------- | ---------------------------- |
| `format`    | `webp`, `avif`, `original`                     | `webp`     | `withFormat()`               |
| `quality`   | integer 1-100; omitted for the format default  | (omitted)  | `withQuality()`              |
| `effort`    | integer 0-9 (0 fastest, 9 smallest output)     | `4`        | `withEffort()`               |
| `lossless`  | boolean                                        | `0`        | `withLossless()`             |
| `width`     | integer 1-16383; omitted for no constraint     | (omitted)  | `withWidth()`                |
| `height`    | integer 1-16383; omitted for no constraint     | (omitted)  | `withHeight()`               |
| `fit`       | `contain`, `cover`, `fill`                     | `contain`  | `withFit()`                  |
| `enlarge`   | boolean                                        | `0`        | `withEnlargementAllowed()`   |
| `strip`     | boolean                                        | `1`        | `withStripMetadata()`        |
| `keep_icc`  | boolean                                        | `1`        | `withKeepIccProfile()`       |
| `animation` | `preserve`, `first_frame`, `reject`            | `preserve` | `withAnimationPolicy()`      |

- **Format.** `original` re-encodes the image in its own format: it is resized, stripped of metadata, or both.
- **Quality.** When no quality is requested, the encoder uses the default of the format it actually writes: 80
  for WEBP, 60 for AVIF, and 85 for JPEG and every other format with a quality setting. A fallback to another
  format therefore takes that format's default. PNG, GIF and BMP are lossless and ignore quality.
- **Effort** never changes visual quality. Each backend maps the 0-9 scale to its own setting, for example cwebp
  `-m 0-6` or avifenc `--speed 10-1`.
- **Fit** applies only when both width and height are set. `contain` fits the image inside the box. `cover` fills
  the box and crops the overflow around the center. `fill` stretches the image to the box.
- **Enlargement.** Without enlargement, no output dimension exceeds the input dimension.
- **Animation.** `preserve` keeps every frame where an available backend writes that format animated (WEBP and GIF);
  otherwise the first frame is converted. `first_frame` always produces a still image. `reject` refuses animated
  input with `animation_rejected`.

`ConversionRequest::fromParameters()` parses the canonical form strictly. Keys and choices are case-sensitive.
Integers are PHP ints or decimal digits without sign, spaces or leading zeros. Booleans are PHP bools, the ints `0`
and `1`, or the strings `"0"`, `"1"`, `"true"` and `"false"`. Anything else is rejected. `toParameters()` writes the
canonical spelling, and `fingerprint()` returns 32 hexadecimal characters that identify what the request asks for,
for use in cache keys. Values that cannot affect the output are folded together, so equivalent requests share a
fingerprint. Combine the fingerprint with the identity of the input to build a cache key.

The ranges above are static. Whether the resolved output fits a converter's limits depends on the host and on the
input, and is checked at conversion time (`image_too_large`). For example, `width=16383&height=16383&fit=fill&enlarge=1`
is a valid request, and its 268-megapixel output is refused by any converter with lower limits.

## Conversion results

`ConversionResult` describes the output file as it was verified after encoding, not as it was requested:

| Method                | Meaning                                                                       |
| --------------------- | ----------------------------------------------------------------------------- |
| `getOutputPath()`     | where the output was written                                                  |
| `getOutputFormat()`   | format sniffed from the output, an `ImageFormat` value                        |
| `getMimeType()`       | its MIME type, such as `image/webp`                                           |
| `getWidth()`, `getHeight()` | dimensions in pixels                                                    |
| `getByteSize()`       | size of the output file                                                       |
| `getFrameCount()`, `isAnimated()` | number of frames                                                  |
| `getBackend()`        | backend that produced the output                                              |
| `getDurationMs()`     | wall-clock time of the conversion                                             |
| `getIsolationLevel()` | isolation level the backend ran at                                            |
| `isPassthrough()`     | whether the output holds the input bytes unchanged                            |
| `getWarnings()`       | printable ASCII sentences: fallbacks, a degraded isolation level, a passthrough |

`toArray()` returns every value except the output path and is safe to send to untrusted clients.
`toInternalArray()` adds the path, for server logs only.

## Formats

| Format | Detected by                                | Input           | Output                          | Animation             |
| ------ | ------------------------------------------ | --------------- | ------------------------------- | --------------------- |
| JPEG   | `FF D8 FF`                                 | default         | with `original`, or as fallback | no                    |
| PNG    | PNG signature                              | default         | with `original`, or as fallback | APNG: first frame only |
| GIF    | `GIF87a`, `GIF89a`                         | default         | with `original`, or as fallback | read and written      |
| BMP    | `BM` plus a known DIB header size          | default         | with `original`, or as fallback | no                    |
| WEBP   | `RIFF` .... `WEBP`                         | opt-in          | yes                             | read and written      |
| AVIF   | ISO-BMFF `ftyp` with an `avif` or `avis` brand | opt-in      | yes                             | not supported         |
| TIFF   | `II*\0`, `MM\0*` (classic TIFF only)       | opt-in          | with `original`, or as fallback | page 0 only           |
| SVG    | first element `<svg`                       | always refused  | no                              | no                    |

Enable more input formats on the builder:

```php
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;

$converter = (new SecureConverterBuilder())
    ->withInputFormats([ImageFormat::JPEG, ImageFormat::PNG, ImageFormat::GIF, ImageFormat::WEBP])
    ->build();
```

Notes:

- **Not identified:** HEIC, plain `mif1` and other non-AVIF brands, BigTIFF, and every non-image file. They are
  refused with `unsupported_format`.
- **TIFF** is decoded from its first page only. Later pages are not counted or checked, and are treated as trailing
  data. TIFF is never animated.
- **AVIF:** still images are read from their primary item. No backend reads or writes AVIF image sequences, so an
  animated AVIF input is refused.
- **Animated GIF and WEBP** are kept animated by vips (GIF and WEBP output) and by gif2webp (GIF to WEBP). APNG is
  converted to its first frame.
- **Unsupported variants** are refused with `unsupported_format` rather than handed to a decoder. Among them: 12- or
  16-bit, two-component, lossless and hierarchical JPEG; BMP with embedded JPEG or PNG data, OS/2 2.x headers, or a
  profile linked by file name; AVIF grids, derived images and non-AV1 items; TIFF compressions other than none,
  CCITT, LZW, JPEG (7), Deflate and PackBits. See `UnsupportedFormatException` for the full list.
- **Variants some decoders do not read**, such as CMYK JPEG, BMP bit fields, a WEBP in the animation layout, or a
  tiled TIFF, are probed per backend. They are given only to a backend that has proved it decodes them.
- `Capabilities::getInputFormats()` lists an enabled format only when at least one available backend decodes it.

## Backends

Backends are tried in this order of preference (`BackendFactory::NAMES`):

| Order | Name       | Program                       | Reads                                 | Writes                                  |
| ----- | ---------- | ----------------------------- | ------------------------------------- | --------------------------------------- |
| 1     | `vips`     | `vips`                        | JPEG, PNG, GIF, WEBP, TIFF, AVIF      | the same formats; animated GIF and WEBP |
| 2     | `cwebp`    | `cwebp`                       | JPEG, PNG, WEBP, TIFF (still)         | WEBP                                    |
| 3     | `gif2webp` | `gif2webp`                    | GIF, still or with every frame        | WEBP, animated WEBP                     |
| 4     | `avifenc`  | `avifenc`                     | JPEG, PNG                             | AVIF                                    |
| 5     | `imagick`  | PHP worker with `ext-imagick` | JPEG, PNG, GIF, BMP, WEBP, AVIF, TIFF | the same formats, still only            |
| 6     | `gd`       | PHP worker with `ext-gd`      | JPEG, PNG, GIF, BMP, WEBP (still)     | JPEG, PNG, GIF, BMP, WEBP               |

The table shows what each program is asked to do. A backend declares only what its probes prove on the host: on
first use it converts small probe images and checks every output, and the result is kept in a capability cache
private to the PHP user (one day by default, invalidated when a program changes). Version numbers are never read.

For each job, the requested format is tried first, then WEBP when AVIF was requested, then the input format. Within
each format, the first backend in the order above is chosen whose capabilities cover the job and whose memory
estimate fits under the ceiling. Choose the enabled backends with `SecureConverterBuilder::withBackends()`;
`BackendFactory::parseNames('vips,gd')` reads a comma-separated configuration value.

[docs/backends.md](docs/backends.md) describes each backend, the selection and fallback rules, how a failure is
attributed to the input or to the backend, and the capability cache.

## Input validation

`ImageInspector` is the first line of defense. It never decodes pixels: no GD, Imagick or vips call is made, and no
LZW, Deflate, Huffman, VP8 or AV1 data is expanded.

- **Magic bytes.** `FormatSniffer` looks at the first 4096 bytes only (see the table in [Formats](#formats)).
- **File size first.** The size is checked against `max_bytes` before anything is read.
- **Bounded parsers.** Each format has a header parser whose loops are bounded by a constant or by the file size.
  Dimensions are checked as soon as the header declares them, and the frame count as soon as it exceeds what the
  limits allow, so a dimension, decompression or frame bomb is refused after a few bytes.
- **Strict structure.** Truncated files, chunks or segments that overrun their container, duplicated boxes or tags
  that decoders disagree on, and unknown critical PNG chunks are refused.
- **Facts for later stages.** The inspector reports the bit depth, the ICC profile, EXIF and other metadata, the
  EXIF or AVIF orientation, and trailing data that decoders skip (such as the archive appended to a GIFAR or a
  JPEG polyglot). A file with trailing data is always re-encoded, never passed through.
- **First image only.** The first page of a TIFF, the first image of a BMP and the primary item of a still AVIF are
  inspected, and the backends decode that image alone.

For AVIF, the AV1 sequence headers bound the frame size, but an AV1 frame header can override it, and only the first
sample of an image sequence is read. AVIF input, which is opt-in, therefore also relies on the memory and process
limits of the backend.

## Isolation

### Levels

| Level     | What the library adds                                                                                     |
| --------- | --------------------------------------------------------------------------------------------------------- |
| `none`    | No isolation guarantee. Programs still start with `proc_open()`, without a process group or limits; a timeout kills the direct child only. |
| `process` | Each program runs in its own session and process group (`setsid`). A timeout sends `SIGTERM` to the whole group, then `SIGKILL`, and what is left of the group is killed after every run. |
| `rlimit`  | `process`, plus kernel resource limits applied with `prlimit`: address space, CPU time, file size, open files, and no core dumps. |
| `sandbox` | `rlimit`, plus bubblewrap: new user, mount, PID, network, IPC, UTS and cgroup namespaces (no network at all), every capability dropped, a read-only view of the system with host configuration hidden, an empty read-only `/tmp`, and the job's working directory as the only writable path. The PID namespace dies with the run, taking every descendant with it. |

At every level, the command is an argument list that never goes through a shell. The environment is built from
scratch: nothing is inherited from the PHP process, which may hold credentials, unless it is listed with
`ProcessOptions::withInheritedEnvironment()`. Compare levels with `IsolationLevel::isAtLeast()`, never with string
comparison.

Descriptors that PHP holds open without close-on-exec, such as a FastCGI connection or a database socket, are
replaced with `/dev/null` in the child. When the open descriptors cannot be listed, because neither `/proc/self/fd`
nor `/dev/fd` is readable (for example under an `open_basedir` that excludes them, or with `scandir()` disabled),
the behavior depends on the level: from `process` up, the run fails with `conversion_failed` before anything starts;
at `none`, which promises no isolation, the descriptors are inherited.

### Detection and policy

`IsolationDetector` runs the same commands the runner would run. It never relies on versions or configuration
alone, because user namespaces are often disabled by Docker's default seccomp profile or by Ubuntu's AppArmor
restriction, and `proc_open()` is often disabled on shared hosting. The result is cached in memory and in a private
file, keyed by the PHP version, SAPI and `disable_functions`, the effective user, the isolation options, the path,
inode, size and modification time of `setsid`, `prlimit`, `bwrap`, `true` and `cat`, the kernel settings that
control unprivileged user namespaces, and how the PHP process is confined: its seccomp mode and filter count,
`no_new_privs` flag and capabilities, its AppArmor profile, and the mounts at and below `/proc` (Docker masks `/proc`
paths unless `systempaths=unconfined`). A change to any of these, such as recreating a container with other security
options, invalidates the cached result at once. The content of a seccomp filter cannot be read, so swapping one
profile for another that installs as many filters keeps the key. Such changes are picked up when the cache expires
(one hour by default), after `IsolationDetector::refresh()`, or after a failed conversion: when choosing or running
the backend fails with `conversion_failed`, the converter makes the detector probe again if its result is at least
a minute old, and the next converter built runs at the level the host provides now. The failed conversion itself is
not retried.

`IsolationDetector::getCachedResults()` lists the unexpired results cached in the detector's directory for every
configuration (each PHP SAPI and set of process options has its own entry), without probing or writing anything. A
diagnostic command can compare them with `detect()` to spot a level probed under other conditions.

`IsolationPolicy` decides what happens with that result:

```php
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\IsolationPolicy;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;

// Refuse to build (isolation_unavailable) unless the host provides at least rlimit.
$converter = (new SecureConverterBuilder())
    ->withIsolationPolicy(IsolationPolicy::strict(IsolationLevel::RLIMIT))
    ->build();
```

- `IsolationPolicy::strict($level)`: the level is a hard minimum. `build()` throws `IsolationUnavailableException`
  when the host provides less.
- `IsolationPolicy::bestEffort($level)` (the default, with `sandbox`): conversions run at the strongest available
  level, and every result carries a warning while it is below the expected one.
- `IsolationPolicy::create('strict', 'rlimit')` reads both values from configuration.

In both modes the effective level is the strongest the host provides. To keep the sandbox out on purpose, disable
bubblewrap in the process options: `(new ProcessOptions())->withToolPath(ProcessOptions::TOOL_BWRAP, null)`.

### What the sandbox does not cover

The isolation levels reduce what a compromised decoder can do. They do not make an exploit impossible, and several
boundaries are worth knowing:

- **Total memory.** `RLIMIT_AS` bounds the address space of each process separately. Memory that no process maps,
  such as `memfd_create()` files and System V shared memory in the private IPC namespace, is not counted at all.
  Bound the total memory of the service that runs PHP with a cgroup limit (systemd `MemoryMax=`,
  `docker run --memory`, a Kubernetes memory limit).
- **Disk.** Programs write temporary files to the working directory, which is on disk. `RLIMIT_FSIZE` bounds each
  file, not their number or total size. Put the working directories on a file system with a quota where that
  matters.
- **Visible system paths.** All of `/usr` is mounted read-only, except `/usr/local/etc`, `/usr/local/src` and
  `/usr/src`, which are shown empty. A host that keeps configuration or secrets elsewhere under `/usr` must add those
  paths with `ProcessOptions::withSandboxHiddenPaths()`, or narrow `withSandboxReadOnlyPaths()`.
- **Kernel.** The sandbox uses namespaces and resource limits; it installs no seccomp filter, and the programs run on
  the host kernel.
- **Below `sandbox`.** At `rlimit` and below, backends run as the PHP user, with that user's file system and network
  access. At `process`, a descendant that starts its own session leaves the process group and is not killed with it.
  At `none` and `process`, nothing bounds memory or CPU time apart from the wall-clock timeout and ImageMagick's own
  limits for the Imagick worker.

## Resource limits

`Limits` bounds the input (file size and header data, before any decoder runs) and the resolved output (before any
encoder runs). The byte size limit applies to the input only.

| Limit                  | Default            | Error             |
| ---------------------- | ------------------ | ----------------- |
| `max_bytes`            | 20971520 (20 MiB)  | `file_too_large`  |
| `max_width`            | 8192 px            | `image_too_large` |
| `max_height`           | 8192 px            | `image_too_large` |
| `max_megapixels`       | 40 (per frame)     | `image_too_large` |
| `max_frames`           | 300                | `image_too_large` |
| `max_total_megapixels` | 400 (all frames)   | `image_too_large` |

```php
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Mfonte\SecureImageConverter\Validation\Limits;

$converter = (new SecureConverterBuilder())
    ->withLimits((new Limits())->withMaxBytes(10 * 1024 * 1024)->withMaxMegapixels(24))
    ->build();
```

Each conversion process is bounded further:

- **Memory.** One memory ceiling (2 GiB by default) admits a job only when the backend's estimate fits under it, and
  is the address space limit of every backend process at the `rlimit` and `sandbox` levels. The estimates are fitted
  to peaks measured with the Ubuntu 24.04 libraries. Under the default ceiling, AVIF output at the default effort is
  admitted up to about 9 to 16 megapixels, depending on the backend, and larger images fall back to WEBP.
- **Time.** Each job's wall-clock timeout follows its work (decoded and encoded megapixels, output format, effort),
  between 30 seconds and one hour. A job estimated above the ceiling is refused before it starts
  (`conversion_timeout`).
- **CPU time** is twice the timeout. File size (256 MiB) and open files (128) are bounded with `prlimit` as well.

[docs/resource-limits.md](docs/resource-limits.md) has the per-backend megapixel ceilings, the time model and every
kernel limit.

## Concurrency

Without a semaphore, nothing bounds the number of conversions that run at once. Give the builder a `Semaphore`
shared by every PHP process of the host:

```php
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\Semaphore;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;

$locks = new LockDirectory('/run/image-converter/locks');

$converter = (new SecureConverterBuilder())
    ->withSemaphore(new Semaphore($locks, 'convert', 4))
    ->withConverterOptions((new ConverterOptions())->withSlotWaitSeconds(10.0))
    ->build();
```

When no slot frees up within the wait (30 seconds by default), `convert()` throws `ConcurrencyLimitException`
(`concurrency_limit`), whose `getRetryAfterSeconds()` is ready for a `Retry-After` header. `KeyedLock` provides
single-flight work, one holder per key. Both rely on `flock()` and work on one host with local file systems.
[docs/concurrency.md](docs/concurrency.md) covers `KeyedLock`, the lock directory rules and the limitations.

## Error handling

Every exception implements `Contract\Exception\ExceptionInterface`. Decide on `getErrorCode()`, never on the
message, which is for humans and may change. Validation messages never quote the file content or its path.

The library does not speak HTTP. The status column is the mapping that `ErrorCode` documents for an HTTP layer
built on the library:

| Code                         | Status | Exception                       | Meaning                                                               |
| ---------------------------- | ------ | ------------------------------- | --------------------------------------------------------------------- |
| `invalid_request`            | 400    | `InvalidRequestException`       | a request parameter is unknown, malformed or out of range             |
| `unsupported_format`         | 415    | `UnsupportedFormatException`    | input format unidentifiable, not enabled, SVG, or an unsupported variant |
| `invalid_image`              | 422    | `InvalidImageException`         | malformed, truncated or spoofed input, or one a sound decoder fails on |
| `animation_rejected`         | 422    | `InvalidImageException`         | animated input under the `reject` animation policy                    |
| `file_too_large`             | 413    | `LimitExceededException`        | input above `max_bytes`                                               |
| `image_too_large`            | 422    | `LimitExceededException`        | input or resolved output above a dimension, pixel or frame limit      |
| `conversion_timeout`         | 422    | `ConversionTimeoutException`    | processing budget exceeded or estimated above the ceiling             |
| `resource_limit`             | 422    | `ResourceLimitException`        | out of memory, CPU time or file size, or every backend above the memory ceiling |
| `isolation_unavailable`      | 503    | `IsolationUnavailableException` | the required isolation level is missing on the host                   |
| `concurrency_limit`          | 503    | `ConcurrencyLimitException`     | no slot or key lock became free in time; retry later                  |
| `conversion_failed`          | 500    | `ConversionFailedException`     | no backend could produce the output, or one failed on a valid image   |
| `output_verification_failed` | 500    | `OutputVerificationException`   | the produced output was not what was asked for                        |
| `internal_error`             | 500    | `InvalidArgumentException`      | programming, configuration or integration error                       |

A code never changes meaning once released. `ErrorCode::all()` lists them. `LimitExceededException` also reports
the limit, its maximum and the actual value (`getLimit()`, `getMaximum()`, `getActual()`, `isOutputLimit()`), and
`InvalidRequestException::getParameter()` names the offending parameter.

## Configuration

`SecureConverterBuilder` is immutable; each `with*()` method returns a modified copy:

| Builder method              | Default                                                                  |
| --------------------------- | ------------------------------------------------------------------------ |
| `withLimits()`              | `Limits` defaults (20 MiB, 8192 x 8192, 40 megapixels, 300 frames, 400 megapixels in all) |
| `withInputFormats()`        | `ImageInspector::DEFAULT_INPUT_FORMATS`: JPEG, PNG, GIF, BMP             |
| `withIsolationPolicy()`     | `IsolationPolicy::bestEffort()`, expecting `sandbox`                     |
| `withIsolationDetector()`   | an `IsolationDetector` with the process options                          |
| `withProcessOptions()`      | `ProcessOptions` defaults                                                |
| `withBackendOptions()`      | `BackendOptions` defaults: 2 GiB memory ceiling, capability cache on     |
| `withBackends()`            | every backend of `BackendFactory::NAMES`                                 |
| `withSemaphore()`           | none                                                                     |
| `withConverterOptions()`    | `ConverterOptions` defaults                                              |

Every option object, with each setting, its default and its range, is described in
[docs/configuration.md](docs/configuration.md).

## Deployment and operations

- **Install bubblewrap and allow user namespaces.** On Ubuntu 24.04, AppArmor restricts unprivileged user
  namespaces. The CI lifts the restriction with `sysctl -w kernel.apparmor_restrict_unprivileged_userns=0`; apply the
  equivalent policy that suits your host. In Docker, the default seccomp and AppArmor profiles forbid the namespaces
  that bubblewrap needs, and conversions then run at the `rlimit` level. The reference image runs its tests with
  `--security-opt seccomp=unconfined --security-opt apparmor=unconfined --security-opt systempaths=unconfined`.
- **Check the level you get.** Read `Capabilities::getIsolationLevel()` at startup, or use a strict policy so that a
  missing sandbox fails loudly instead of degrading.
- **Bound total memory with a cgroup** (see [What the sandbox does not cover](#what-the-sandbox-does-not-cover)), and
  size the semaphore so that slots multiplied by the memory ceiling fit in it.
- **Give each application private directories.** On a host shared with other users, set the capability cache
  directory (`BackendOptions::withCapabilityCache()`), the isolation cache directory (`IsolationDetector`), and the
  working directory parent (`ConverterOptions::withWorkingDirectoryParent()`) to directories that only the PHP user
  can reach. Put the lock directory under `/run/<app>` or `/var/lib/<app>`.
- **PHP-FPM.** `PHP_BINARY` under FPM is the FPM binary. The workers then use `php` from the search path, or the CLI
  set with `BackendOptions::withPhpBinary()`.
- **Warm the caches.** The first use of each backend probes it, which takes seconds and dozens of sandboxed runs.
  Calling `getCapabilities()` once at deployment fills the capability cache for later processes.
- **Logs.** Pass a PSR-3 logger with `BackendOptions::withLogger()` (an unusable capability cache) and to
  `IsolationDetector` (the detected level, and probe details at debug level).

## Performance

The repository has no benchmark suite, and this section quotes no throughput figures. The time and memory models
above come from measurements recorded in the code and test fixtures:

- **Memory:** the per-backend peaks of `tests/Fixtures/backend/memory-peaks.csv`, measured as described in
  [docs/resource-limits.md](docs/resource-limits.md#memory-ceiling). For example, at effort 4 and 8.3 megapixels,
  AVIF peaked at 831 MiB with avifenc and 1086 MiB with vips for a screenshot, and at 212 and 568 MiB for noise.
- **Time:** the encode rates of `ProcessingBudget` are about twice the slowest single-thread time measured with the
  packages of the reference image (Ubuntu 24.04), on screenshots and noise. The measured rates were about 1 second
  per megapixel for WEBP at effort 4, 25 at effort 9, 4 for GIF, 7 to 14 for AVIF at effort 4 and 5, and 146 for AVIF
  at effort 8 and 9. AVIF at effort 9 of a 13-megapixel image took about 1880 seconds of CPU time.
- **Validation:** the JPEG parser searches scan data with PCRE. With the JIT compiler (`pcre.jit=1`, the default)
  this takes about 30 ms for 20 MiB on PHP 7.2, and several times longer without it.

These numbers depend on the machine, the content and the library versions. Treat them as orders of magnitude.

## Testing and development

```bash
composer install
composer test -- --exclude-group backends --exclude-group security   # PHPUnit 11
composer test -- --exclude-group backends,security                   # PHPUnit 8.5 to 10.5
composer ci                                                          # style, static analysis and tests
```

The `backends`, `isolation`, `security`, `fuzz` and `root` groups need the conversion programs, bubblewrap with user
namespaces, or root. The reference image in [`tests/docker/Dockerfile`](tests/docker/Dockerfile) holds every backend
and isolation tool from the Ubuntu 24.04 packages, and its header documents how to build and run it.

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs the suite on PHP 7.2 to 8.5 with the lowest and the
highest dependencies, the isolation tests with and without user namespaces, the backend tests with every backend and
with each one alone, the security corpus and fuzz runs in the reference image, PHPStan at level max, PHP CS Fixer,
and a Trivy scan of the image, which [`nightly.yml`](.github/workflows/nightly.yml) repeats every night.
[CONTRIBUTING.md](CONTRIBUTING.md) has the details.

## Versioning and compatibility

The public API is every class and method that is not marked `@internal`. Classes and methods marked `@internal`,
among them everything in `Backend\Internal`, `Backend\Worker` and `Validation\Parser`, are not covered by the
backward compatibility promise and may change in any release.

Some parts of the contract carry their own stability rules:

- An error code never changes meaning once released.
- The `Capabilities` array is versioned by its `schema` key (currently 1), and `fromArray()` rejects any other
  version.
- `ConversionRequest::fingerprint()` hashes a versioned canonical form. The version changes whenever the meaning of a
  parameter changes, so cache entries produced under the old meaning stop matching.
- Serialized value objects are validated again when they are unserialized, and the serialized form is the same on
  every supported PHP version.

Supported PHP versions: 7.2.5 to 8.5, 64-bit. Changes are recorded in [CHANGELOG.md](CHANGELOG.md).

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup, the checks that CI runs
and the commit conventions.

## Security

Please do not report vulnerabilities in public issues. See [SECURITY.md](SECURITY.md) for private reporting through
GitHub Security Advisories and for the scope of the security model.

## License

BSD 3-Clause License. Copyright (c) 2026, Maurizio Fonte. See [LICENSE](LICENSE).
