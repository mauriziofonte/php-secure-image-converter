# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-11-09

### Added

- `Contract\ConverterInterface`, with the immutable `ConversionRequest`, `ConversionResult` and `Capabilities` value
  objects, the `ImageFormat` and `IsolationLevel` constants, and the `Contract\Exception` hierarchy with stable error
  codes (`ErrorCode`) and their recommended HTTP statuses.
- `ConversionRequest` parameters `format` (`webp`, `avif`, `original`), `quality`, `effort`, `lossless`, `width`,
  `height`, `fit` (`contain`, `cover`, `fill`), `enlarge`, `strip`, `keep_icc` and `animation` (`preserve`,
  `first_frame`, `reject`), with a strict canonical string form (`fromParameters()`, `toParameters()`) and a
  versioned `fingerprint()` for cache keys.
- `Pipeline\SecureConverter` and `SecureConverterBuilder`: private copy of the input, header inspection, output
  limits checked before any encoder runs, backend selection with the AVIF to WEBP to input-format fallback, output
  verification, "keep the smaller file" passthrough, and atomic publication of the output.
- `Validation\ImageInspector`: content-based format detection and bounded header parsers for JPEG, PNG and APNG,
  GIF, BMP, WEBP, AVIF and TIFF, with size, dimension, pixel and frame limits (`Limits`). JPEG, PNG, GIF and BMP are
  enabled by default; WEBP, AVIF and TIFF are opt-in; SVG is always refused.
- Backends for libvips, cwebp, gif2webp and avifenc, and PHP CLI workers for Imagick and GD, with capabilities
  established by converting probe images and kept in a private capability cache.
- Memory estimates for every backend, fitted to peaks measured with the Ubuntu 24.04 libraries, and a configurable
  memory ceiling that admits jobs and bounds the address space of each backend process.
- `Pipeline\ProcessingBudget`: a wall-clock timeout and CPU time limit for each job, derived from its work, with
  admission control against a configurable ceiling.
- `Isolation`: the `none`, `process`, `rlimit` and `sandbox` levels, run-time detection (`IsolationDetector`) with
  a private cache, strict and best-effort policies (`IsolationPolicy`), `prlimit` resource limits
  (`ResourceLimits`), and a bubblewrap sandbox without network access.
- The isolation cache is keyed by the process confinement too (seccomp mode and filter count, `no_new_privs`,
  capabilities, AppArmor profile, mounts at and below `/proc`), and a conversion that fails with `conversion_failed`
  makes the detector probe again when its result is at least a minute old, so that a cached level that stopped
  working is not kept for the whole cache TTL.
- `IsolationDetector::getCachedResults()`: the unexpired cached results of every configuration, read without probing,
  for diagnostics.
- A restrictive ImageMagick policy for the Imagick worker (`resources/imagemagick/policy.xml`).
- `Concurrency`: a counting `Semaphore` and a per-key `KeyedLock` on `flock()`, with bounded waiting and a
  `Retry-After` hint on `ConcurrencyLimitException`.
- Support for PHP 7.2.5 to 8.5 on 64-bit builds, with `psr/log` 1.1, 2.x or 3.x as the only runtime dependency.

[1.0.0]: https://github.com/mauriziofonte/php-secure-image-converter/releases/tag/v1.0.0
