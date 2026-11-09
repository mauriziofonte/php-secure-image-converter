# Backends

This page describes the conversion backends of `mfonte/secure-image-converter`, how one is chosen for each job, and how
their capabilities are discovered. See the [README](../README.md) for an overview.

## Order of preference

Backends are tried in this order of preference (`BackendFactory::NAMES`):

| Order | Name       | Program                       | Reads                                 | Writes                                  |
| ----- | ---------- | ----------------------------- | ------------------------------------- | --------------------------------------- |
| 1     | `vips`     | `vips`                        | JPEG, PNG, GIF, WEBP, TIFF, AVIF      | the same formats; animated GIF and WEBP |
| 2     | `cwebp`    | `cwebp`                       | JPEG, PNG, WEBP, TIFF (still)         | WEBP                                    |
| 3     | `gif2webp` | `gif2webp`                    | GIF, still or with every frame        | WEBP, animated WEBP                     |
| 4     | `avifenc`  | `avifenc`                     | JPEG, PNG                             | AVIF                                    |
| 5     | `imagick`  | PHP worker with `ext-imagick` | JPEG, PNG, GIF, BMP, WEBP, AVIF, TIFF | the same formats, still only            |
| 6     | `gd`       | PHP worker with `ext-gd`      | JPEG, PNG, GIF, BMP, WEBP (still)     | JPEG, PNG, GIF, BMP, WEBP               |

The table shows what each program is asked to do. What a backend actually declares is what its probes prove on
the host (see [Capability discovery](#capability-discovery)).

- **vips** names the loader of the sniffed format on the command line. When resizing, it uses `vips thumbnail`
  with `VIPS_BLOCK_UNTRUSTED=1`. Discovery checks that the variable is honored (libvips before 8.13 ignores it).
  Where it is not, the backend declares no fit and converts without resizing only. libvips reads BMP only through
  ImageMagick, which that variable blocks, so vips does not take BMP.
- **gif2webp** decodes one frame at a time, so it converts animations too large for vips under the memory ceiling.
  It cannot extract a single frame, and does not resize.
- **avifenc** runs on one thread (`-j 1`) at 8 bits per channel, without resizing. Lossy output is 4:2:0.
- **imagick** runs a PHP CLI worker under the restrictive ImageMagick policy shipped in
  [`resources/imagemagick/policy.xml`](../resources/imagemagick/policy.xml). The policy denies every coder except the
  library's raster formats, and every delegate, filter and indirect read. Each read names its coder and its first
  image (for example `TIFF:input.tiff[0]`).
- **gd** runs a PHP CLI worker, keeps no metadata (the ICC profile is stripped too), and leaves AVIF to vips and
  avifenc. It reads the first frame of a GIF and the default image of an APNG, and does not read animated WEBP.
- **imagick** and **gd** convert an animated input to its first frame only. Before each job, the Imagick worker sets
  ImageMagick's memory and map limits to the memory ceiling and its disk limit to 0, so the pixel cache never
  spills to disk; it runs on one thread, with OpenCL off.
- The PHP workers run `php -n` with `memory_limit=-1`. `-n` loads no `php.ini`, so neither the host configuration
  nor its secrets reach the worker. The extension is loaded explicitly; see `BackendOptions::withPhpExtensionDirectory()`
  for hosts whose extensions live outside the built-in directory.

## Encoder settings

Each backend maps the request onto its own program:

| Backend    | Quality                  | Effort (0 to 9)                                       | Metadata                                  |
| ---------- | ------------------------ | ----------------------------------------------------- | ----------------------------------------- |
| `vips`     | `Q` (JPEG, WEBP, AVIF)   | WEBP effort 0 to 6 (scaled), AVIF effort 0 to 8, PNG zlib level | `keep=none`, `keep=icc`, or everything |
| `cwebp`    | `-q`                     | `-m 0` to `6` (scaled); lossless: `-z 0` to `9`        | `-metadata none`, `icc` or `all`          |
| `gif2webp` | `-mixed -q`              | `-m 0` to `6` (scaled)                                | `-metadata none`, `icc` or `all`          |
| `avifenc`  | `-q`, or the equivalent quantizer range on older libavif | `-s 10` (effort 0) to `1` (effort 8 and 9) | `--ignore-exif`, `--ignore-xmp`, `--ignore-icc` |

vips writes AVIF with `compression=av1` and `bitdepth=8`, and runs with `VIPS_CONCURRENCY=1` and
`MALLOC_ARENA_MAX=2` to keep its address space predictable. Lossless mode maps to `lossless=true` (vips), `-z`
(cwebp) and `-l` (avifenc).

## Choosing backends

Choose the enabled backends with `SecureConverterBuilder::withBackends()`. `BackendFactory::parseNames('vips,gd')`
reads a comma-separated configuration value. The order of preference stays the one above whatever order the list
uses.

## Selection and fallback

For each job, `BackendResolver` tries the output formats in fallback order: the requested format, then WEBP when
AVIF was requested, then the input format. For an animated job, each format is tried first with every frame and then
with the first frame only. For each variant, the first backend in order of preference is chosen if:

- its proved capabilities cover the input (format and variant), the output, the fit and the lossless mode;
- its memory estimate for the job is not above the memory ceiling;
- it keeps the requested metadata. When every capable backend drops some metadata, the first one is chosen anyway,
  with a warning: metadata is a preference, not a requirement.

When nothing can perform the job, the exception lists the reason for every candidate. It is `unsupported_format`
when no available backend decodes the input or encodes any fallback format, `resource_limit` when only the memory
ceiling stood in the way, and `conversion_failed` otherwise.

A backend that fails on an input without hitting a limit is checked once more: the probe image of the same format is
converted with the job's parameters. If that succeeds, the input is blamed (`invalid_image`); otherwise the backend
is (`conversion_failed`). A failure that may come from the host, such as a full disk, stays `conversion_failed`. No
other backend is tried after a failure, because each additional decoder that reads a hostile input adds attack
surface.

## Capability discovery

Backends are never trusted on version numbers, because distributions backport fixes and features without changing
them. On first use, each backend converts small probe images
([`resources/probe`](../resources/probe)) and checks every output with the same inspector that validates inputs. It
declares only what those conversions prove: input and output formats, input variants, fits, first-frame and
animated conversion, lossless mode, ICC profile and metadata handling, and orientation.

The result is kept in a capability cache shared by the PHP processes of the same user:

- By default it lives in `sys_get_temp_dir()/secure-image-converter-<euid>`, and entries expire after one day
  (`BackendOptions::DEFAULT_CAPABILITY_CACHE_TTL_SECONDS`).
- Entries are keyed by the programs (path, size, modification time, inode), the isolation level and the sandbox
  options, so replacing a binary invalidates them at once. The TTL covers libraries updated without their programs.
- The directory must be owned by the effective user and not writable by group or others. A cache file must be a
  regular file with the same properties, checked with `lstat()`. Anything else is ignored and the backends probe
  again, so another local user cannot plant capabilities. Without the `posix` extension the cache is not used.
- On hosts where several users share the temporary directory, pass a directory that only the PHP user can reach:
  `->withCapabilityCache(true, '/srv/app/var/cache/image-converter')`. It is created with mode `0700` when missing.

The isolation detector keeps its own cached result in the same directory (default TTL one hour).
