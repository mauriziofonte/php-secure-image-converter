# Contributing

Thank you for helping to improve `mfonte/secure-image-converter`. This guide covers the development setup, the checks
that CI runs, and the conventions for commits and pull requests.

Please report security vulnerabilities privately, as described in [SECURITY.md](SECURITY.md), never in a public
issue or pull request.

## Development setup

You need PHP 7.2.5 to 8.5 (64-bit) with the `hash`, `json` and `posix` extensions, Composer 2, and Git. The library
has no lock file, because one lock cannot serve both PHP 7.2 and PHP 8.5: Composer resolves the dependencies for the
PHP version you run.

```bash
git clone https://github.com/mauriziofonte/php-secure-image-converter.git
cd php-secure-image-converter
composer install
```

The style and static-analysis tools do not install on PHP 7.2, so each one lives in `tools/<name>/` with its own
`composer.json`. Install them with PHP 7.4 or later:

```bash
composer install --working-dir=tools/phpstan
composer install --working-dir=tools/php-cs-fixer
```

## Running the checks

| Command               | What it runs                                                                    |
| --------------------- | ------------------------------------------------------------------------------- |
| `composer test`       | the PHPUnit suite                                                               |
| `composer cs:check`   | PHP CS Fixer in dry-run mode, printing a diff                                   |
| `composer cs:fix`     | PHP CS Fixer, fixing files in place                                             |
| `composer analyse`    | PHPStan at level max, once as PHP 7.2.5 and once as PHP 8.5                     |
| `composer ci`         | `cs:check`, `analyse` and `test`, in this order                                 |

Composer resolves PHPUnit 8.5, 9.6, 10.5 or 11.5, depending on the PHP version. The suite that the CI tests job
runs leaves out the groups that need real conversion programs. PHPUnit 8 to 10 keep only the last `--exclude-group`
option, and PHPUnit 11 warns about a comma-separated list, which fails the run, so the spelling depends on the
version:

```bash
composer test -- --exclude-group backends --exclude-group security   # PHPUnit 11
composer test -- --exclude-group backends,security                   # PHPUnit 8.5 to 10.5
```

Some ownership checks need a directory or a symbolic link owned by another user, which only root can create. Those
tests skip themselves in a normal run, and CI runs them again as root:

```bash
sudo "$(command -v php)" vendor/bin/phpunit --group root
```

Every PHP file must also lint on the oldest supported PHP, because static analysis does not report every construct
that PHP 7.2 rejects. CI runs `php -l` on every file of `src`, `resources`, `tests` and `tools/require-tool.php`
with each PHP version, and validates the manifests with `composer validate --strict`, for the root and for each
`tools/*/composer.json`.

## Test groups

| Group              | Needs                                                                                     |
| ------------------ | ----------------------------------------------------------------------------------------- |
| `isolation`        | `setsid`, `prlimit` and `bwrap` with unprivileged user namespaces; tests skip what the host lacks |
| `sandbox`          | bubblewrap, as above                                                                      |
| `backends`         | the conversion programs (`vips`, `cwebp`, `gif2webp`, `avifenc`) and the `gd` and `imagick` extensions |
| `slow`             | as `backends`; highest-effort AVIF of large images, which takes over an hour            |
| `security`, `fuzz` | the reference image: the hostile-input corpus and the fuzz runs                          |
| `concurrency`      | `proc_open()`: many worker processes compete for real locks on a local file system       |
| `root`             | root                                                                                      |

Environment variables make missing prerequisites fail instead of skip:

- `SIC_EXPECTED_ISOLATION_LEVEL=sandbox` makes a test that needs a level the host lacks fail.
- `SIC_EXPECTED_BACKENDS=vips,cwebp,gif2webp,avifenc,imagick,gd` makes a test of a backend that is missing, or lacks
  its reference capabilities, fail.
- `SIC_BACKENDS` limits the enabled backends, for example `SIC_BACKENDS=gd`.

## The reference image

[`tests/docker/Dockerfile`](tests/docker/Dockerfile) builds an Ubuntu 24.04 image with PHP, GD, Imagick, every
backend program, bubblewrap and util-linux. It is for tests only and is never published. Install the Composer
dependencies on the host for PHP 8.3 (the PHP of the image) first, then:

```bash
docker build --network host -t sic-reference tests/docker
docker run --rm --network host \
  --security-opt seccomp=unconfined --security-opt apparmor=unconfined \
  --security-opt systempaths=unconfined \
  -v "$PWD:/src" -w /src -e SIC_EXPECTED_ISOLATION_LEVEL=sandbox \
  -e SIC_EXPECTED_BACKENDS=vips,cwebp,gif2webp,avifenc,imagick,gd \
  sic-reference php vendor/bin/phpunit --group backends --exclude-group slow
```

Docker's default seccomp and AppArmor profiles forbid the user namespaces that bubblewrap needs: without the
security options, the tests run at the `rlimit` level and the sandbox tests skip. On a host with Ubuntu 24.04's
AppArmor restriction, also run `sysctl -w kernel.apparmor_restrict_unprivileged_userns=0`. The security corpus and
the fuzz runs take the same options with `--group security` and `--group fuzz`. Their variables, such as
`SIC_FUZZ_SEED`, are listed in the fuzz tests.

## What CI runs

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every push and pull request:

- **Composer validate:** the root and tool manifests, with `--strict`.
- **Tests:** PHP 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5, each with the lowest and the highest dependencies.
  Each job lints every file, audits the lowest dependencies with `composer audit`, runs the suite without the
  `backends` and `security` groups, then the `root` group as root.
- **Static analysis:** `composer audit`, `composer cs:check` and `composer analyse`, on PHP 8.4.
- **Isolation:** the `isolation` group on PHP 7.2 and 8.5 with bubblewrap, expecting `sandbox`, then again with user
  namespaces disabled, expecting `rlimit`.
- **Backends:** the `backends` group on PHP 7.2 and 8.5, with every backend and with each backend alone, at the
  sandbox level; the `slow` group once, on PHP 8.5.
- **Reference image:** the image built for `linux/amd64` and `linux/arm64`, the `backends` group in it as an
  unprivileged user, and the `security` and `fuzz` groups in it with 4 GiB of memory and a fixed seed.
- **Trivy:** a vulnerability scan of the reference image. Any critical vulnerability fails the job, unless
  `tests/docker/.trivyignore` lists it with a reason and an expiry date.
  [`nightly.yml`](.github/workflows/nightly.yml) repeats the scan every night.

## Coding guidelines

- Keep the code compatible with PHP 7.2.5: no typed properties, no trailing commas in calls, no union types. PHPStan
  runs as PHP 7.2.5 and as PHP 8.5, and the CI lint catches the rest.
- Every file declares `strict_types=1`. Follow the style of `.php-cs-fixer.dist.php` (`composer cs:fix`).
- Add no runtime dependency beyond `psr/log` and the PHP extensions listed in `composer.json`.
- Value objects are immutable: `with*()` methods validate and return a modified copy.
- Every failure a caller can see is an exception that implements `Contract\Exception\ExceptionInterface`, with an
  error code from `ErrorCode`. Never change the meaning of an existing code.
- Untrusted input never reaches a command line, a shell or an exception message.
- Add or update tests with every change. A change to a backend's memory estimate needs measured rows in
  `tests/Fixtures/backend/memory-peaks.csv`, which `MemoryEstimateTest` checks.
- Update the documentation (README, `docs/`) and [CHANGELOG.md](CHANGELOG.md) when behavior changes.

## Commits and pull requests

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/):

```text
type(scope): imperative summary of at most 72 characters

The body explains why the change is needed and what it affects, wrapped
at 72 characters.
```

Use the types `feat`, `fix`, `perf`, `refactor`, `style`, `test`, `docs`, `build`, `ci` and `chore`. Mark a breaking change
with `!` after the type or scope, and describe it in a `BREAKING CHANGE:` footer. Keep one logical change per
commit, and make sure every commit leaves the suite green.

Before you open a pull request:

1. Run `composer ci` on the newest PHP you have, and the suite on the oldest one you can.
2. Describe what the change does and why, and how you tested it, including the backends and isolation level of
   your host.
3. Keep the pull request focused. Unrelated clean-ups belong in a separate one.

By contributing, you agree that your contributions are licensed under the [BSD 3-Clause License](LICENSE).
