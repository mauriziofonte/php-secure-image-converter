# Security policy

`mfonte/secure-image-converter` converts untrusted images, so security reports are taken seriously. This page
explains which versions receive fixes, how to report a vulnerability privately, and what is in scope.

## Supported versions

| Version | Supported                    |
| ------- | ---------------------------- |
| 1.x     | yes                          |

## Reporting a vulnerability

Do not open a public issue, discussion or pull request for a vulnerability.

Report it privately through GitHub's private vulnerability reporting:

1. Open the repository's [Security advisories](https://github.com/mauriziofonte/php-secure-image-converter/security/advisories/new)
   page ("Report a vulnerability" on the Security tab).
2. Describe the issue, the affected version, the isolation level and the backends involved, and the steps or
   input needed to reproduce it. Attach a minimal input file when the report depends on one.
3. Say whether you want to be credited, and under which name.

The conversation continues in the advisory thread. The advisory stays private while a fix is prepared, and is
published once a fixed release is available.

## Scope

The library's job is to keep a hostile image from harming the host or the caller, within the isolation level that
the host provides. Reports in these areas are in scope:

- an input that passes `ImageInspector` although it exceeds the configured limits, or is identified as a format it
  does not hold;
- an input that reaches a decoder the library should not have given it to, such as a disabled format, an
  unsupported variant, or a backend that has not proved it decodes the input;
- output verification accepting a file that does not match the job, or a passthrough returning bytes that do not
  satisfy the request (trailing data, metadata that should have been stripped);
- a way for a converted program to escape the guarantees of its isolation level as the library configures it: the
  process group, the `prlimit` limits, or the bubblewrap sandbox options;
- untrusted data reaching a command line, a shell, the environment of a program, or an exception message;
- path handling in `SecureConverter::convert()`, the working directories, the lock directory, or the private cache
  directories, such as a symbolic-link or time-of-check to time-of-use attack by another local user;
- a cache entry (isolation level or backend capabilities) that another local user can plant or alter;
- a resource exhaustion that bypasses the memory ceiling, the processing budget or the process limits.

Out of scope:

- vulnerabilities in the conversion programs and libraries themselves (libvips, libwebp, libavif, libheif, libaom,
  ImageMagick, GD, libjpeg, libpng and others). Please report those upstream. A report that the library hands a
  known-dangerous input to such a program, where its checks should have refused it, is in scope;
- hosts running at the `none` or `process` isolation level, which by design bound neither memory nor CPU time, and
  give programs the PHP user's file system and network access;
- limitations that the documentation describes, such as memory that `RLIMIT_AS` does not count (`memfd_create()`
  files and System V shared memory), or the total size of a job's temporary files;
- denial of service within the configured limits, for example many slow conversions of images that the limits
  allow;
- issues that need a privileged local attacker (root, or the PHP user itself), or a modified copy of the library.

## Security model

The [README](README.md#isolation) describes the isolation levels, what the sandbox does and does not cover, and the
recommended deployment, including a cgroup memory limit for the service that runs PHP. The library is tested
against a corpus of hostile inputs and with fuzz runs in CI, but no software that decodes untrusted images is free
of risk: run conversions at the strongest isolation level your host allows.
