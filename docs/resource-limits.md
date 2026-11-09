# Resource limits

This page describes every limit that `mfonte/secure-image-converter` applies to a conversion: the input and output
limits, the memory ceiling, the time budget and the kernel limits of each process. See the [README](../README.md) for
an overview, and [configuration.md](configuration.md) for every setting.

## Input and output limits

`Limits` holds the size, dimension, pixel and frame limits. They apply to the input (file size and header data,
before any decoder runs) and to the resolved output (before any encoder runs). The byte size limit applies to the
input only.

| Limit                  | Default            | Error             |
| ---------------------- | ------------------ | ----------------- |
| `max_bytes`            | 20971520 (20 MiB)  | `file_too_large`  |
| `max_width`            | 8192 px            | `image_too_large` |
| `max_height`           | 8192 px            | `image_too_large` |
| `max_megapixels`       | 40 (per frame)     | `image_too_large` |
| `max_frames`           | 300                | `image_too_large` |
| `max_total_megapixels` | 400 (all frames)   | `image_too_large` |

Every value is an integer from 1 to 2^31 - 1. Independently of these limits, an output side is never above 16383
pixels, the largest dimension WEBP can encode.

```php
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Mfonte\SecureImageConverter\Validation\Limits;

$converter = (new SecureConverterBuilder())
    ->withLimits((new Limits())->withMaxBytes(10 * 1024 * 1024)->withMaxMegapixels(24))
    ->build();
```

## Memory ceiling

`BackendOptions` sets one memory ceiling, 2 GiB by default. It has two roles:

- **Admission.** Before a job runs, the backend estimates the address space it needs, from the pixels it decodes and
  encodes, the output format, the effort, the lossless mode, the bit depth and the input format. A job whose estimate
  is above the ceiling is not started. The resolver then tries another backend or fallback format, and throws
  `resource_limit` when none fits.
- **Enforcement.** At the `rlimit` and `sandbox` levels, every backend process runs with the ceiling as its address
  space limit. At `none` and `process`, no address space limit applies. The Imagick worker always sets ImageMagick's
  memory and map limits to the ceiling.

The estimates come from measured peaks. Each backend's coefficients are fitted to the peak address space (`VmPeak`) of
its own command line, recorded in [`tests/Fixtures/backend/memory-peaks.csv`](../tests/Fixtures/backend/memory-peaks.csv).
The peaks were measured with the libraries of the reference image (Ubuntu 24.04: libvips 8.15.1, libwebp 1.3.2,
libheif 1.17.6, libaom 3.8.2, libavif 1.0.4, ImageMagick 6.9.12, PHP 8.3 with GD 2.3), on several content classes
(noise, screenshots, 256-color images), mostly from 1 to 20 megapixels, with enlargements up to 36. A unit test
keeps every estimate at least 20% plus 32 MiB above every measured row. The estimate is an upper bound of what was measured, not of every image:
photographs, larger images and other library versions were not measured, and can need more.

Largest still image, in megapixels, that each backend admits under the default 2 GiB ceiling (8 bits per channel,
no resize, a JPEG, PNG, GIF, BMP or TIFF input; values computed from the estimates). vips holds a decoded WEBP or
AVIF input in 12 bytes per pixel instead of 4, so from such an input its WEBP ceilings drop to 21 megapixels at
effort 0 to 8 and 15 megapixels at effort 9, and its lossless WEBP ceiling to 21 megapixels at any effort:

| Output               | Effort | vips | cwebp | gif2webp | avifenc | imagick | gd  |
| -------------------- | ------ | ---- | ----- | -------- | ------- | ------- | --- |
| JPEG, PNG, TIFF, BMP | any    | 88   |       |          |         | 55      | 126 |
| GIF                  | any    | 25   |       |          |         | 51      | 126 |
| WEBP                 | 0 to 8 | 23   | 47    | 27       |         | 33      | 38  |
| WEBP                 | 9      | 16   | 29    | 21       |         | 23      | 38  |
| WEBP lossless        | 0 to 7 | 23   | 46    | 30       |         | 32      | 35  |
| WEBP lossless        | 8, 9   | 23   | 24    | 24       |         | 32      | 35  |
| AVIF                 | 0 to 2 | 12   |       |          | 16      | 13      |     |
| AVIF                 | 3, 4   | 9    |       |          | 16      | 10      |     |
| AVIF                 | 5 to 9 | 9    |       |          | 13      | 10      |     |
| AVIF lossless        | 0 to 4 | 9    |       |          | 14      |         |     |
| AVIF lossless        | 5 to 9 | 9    |       |          | 12      |         |     |

At the default effort of 4, AVIF output is admitted up to about 9 to 16 megapixels, depending on the backend. Larger
images fall back to WEBP. Inputs of 16 bits per channel need more. The ceiling bounds one process, so size it
together with the semaphore: slots multiplied by the ceiling must fit in the memory the host can give to
conversions.

## Time and CPU

`ProcessingBudget` derives each job's wall-clock timeout from its work:

```text
scale x (15 s + 1 s per decoded megapixel + encode seconds per megapixel x encoded megapixels)
```

The result is clamped between a floor of 30 seconds and a ceiling of one hour. The encode rate depends on the
output and the effort:

| Output               | Effort 0-2 | 3-4 | 5-6 | 7   | 8-9 |
| -------------------- | ---------- | --- | --- | --- | --- |
| JPEG, PNG, TIFF, BMP | 4          | 4   | 4   | 4   | 4   |
| GIF                  | 8          | 8   | 8   | 8   | 8   |
| WEBP                 | 6          | 6   | 15  | 60  | 60  |
| WEBP lossless        | 10         | 20  | 80  | 160 | 160 |
| AVIF                 | 6          | 15  | 60  | 150 | 300 |
| AVIF lossless        | 12         | 30  | 120 | 300 | 600 |

- The CPU time limit is twice the timeout, because AV1 encoders use helper threads, so the timeout normally stops a
  job first. Running out of CPU time is `resource_limit`; running out of wall-clock time is `conversion_timeout`.
- A job whose estimate exceeds the ceiling is refused before it starts, with `conversion_timeout`.
- With the default limits, every request with the default parameters (WEBP, effort 4) fits under the default
  ceiling. Slow encodings of large images do not, for example AVIF above about 23 megapixels at effort 7 and about
  11 at effort 8 or 9. Raise the ceiling with `withMaxSeconds()`, or use `withScale()` on a slower host.

## Process limits

At the `rlimit` and `sandbox` levels, `prlimit` sets each limit as both the soft and the hard limit, so a process
cannot raise it again:

| Limit         | Default for backends              | When it is reached                          |
| ------------- | --------------------------------- | ------------------------------------------- |
| address space | the memory ceiling (2 GiB)        | allocations fail                            |
| CPU time      | twice the job's timeout           | `SIGXCPU`, then `SIGKILL`                   |
| file size     | 256 MiB                           | `SIGXFSZ`                                   |
| open files    | 128                               | `EMFILE`                                    |
| processes     | disabled                          | `fork()` fails                              |
| core size     | 0 (not tunable)                   | no core dump is written                     |

`ResourceLimits` holds the runner's own defaults (address space 1 GiB, CPU time 60 seconds), which apply to runs
other than backend jobs, such as the isolation probes. The process limit is disabled by default because the kernel
counts every process of the user. Enable it only when conversions run under a dedicated user.
