# Reference test image of the library: PHP CLI with GD and Imagick, every
# backend program (vips, ImageMagick, cwebp, gif2webp, avifenc) and the
# isolation tools (bubblewrap, prlimit from util-linux), all from the
# packages of Ubuntu 24.04. CI builds it for linux/amd64 and linux/arm64,
# runs the backends group and the security corpus in it, and scans it with
# Trivy; it is never published. The Backends jobs of CI install the same
# packages on the runner, with the PHP versions of setup-php.
#
#   docker build --network host -t sic-reference tests/docker
#   docker run --rm --network host \
#     --security-opt seccomp=unconfined --security-opt apparmor=unconfined \
#     --security-opt systempaths=unconfined \
#     -v "$PWD:/src" -w /src -e SIC_EXPECTED_ISOLATION_LEVEL=sandbox \
#     -e SIC_EXPECTED_BACKENDS=vips,cwebp,gif2webp,avifenc,imagick,gd \
#     sic-reference php vendor/bin/phpunit --group backends --exclude-group slow
#
# Install the Composer dependencies on the host first, for PHP 8.3 (the PHP
# of the image). The group "slow" (highest-effort AVIF of large screenshots
# and GIFs) takes over an hour: run it on its own with --group slow. The
# image runs as the unprivileged user "tester" (uid 10001), as a deployment
# would; CI runs it as --user 65534:65534 -e HOME=/tmp, which works the
# same, and --user root is there for debugging only. The security corpus
# and the fuzz runs take the same options with --group security and
# --group fuzz; their variables (SIC_FUZZ_SEED and others) are listed in
# the fuzz tests.
#
# Docker's default seccomp and AppArmor profiles forbid the user namespaces
# that bubblewrap needs: without the security options the tests run at the
# rlimit level and the sandbox tests skip. On a host with Ubuntu 24.04's
# AppArmor restriction, also run
# "sysctl -w kernel.apparmor_restrict_unprivileged_userns=0".
#
# The base image is pinned by the digest of its multi-platform index, which
# Dependabot updates. Every build installs the current security updates of
# the distribution (apt-get upgrade), so a rebuild picks up a fix without a
# new digest: the image follows the archive, it is not a frozen snapshot.
#
# Vulnerability policy (the trivy jobs of ci.yml and nightly.yml): Trivy
# scans the image on every push and every night, and reports the high and
# critical vulnerabilities. Any critical one fails the scan, fixed or not,
# unless .trivyignore (next to this file) lists it:
# - with a fixed package, it is fixed by rebuilding (apt-get upgrade), or
#   by a new base digest when the fix is in the base image; it is never
#   listed.
# - without a fixed package (unfixed), the package is removed from the
#   image when the tests can do without it; a package a backend needs
#   (and so the conversion of untrusted images) is listed only with the
#   reason the vulnerability does not reach the conversion, and an expiry
#   date of at most 30 days, after which the scan fails again and the entry
#   is reviewed.
# The image only runs tests; the policy for deployments is the operator's.
FROM mirror.gcr.io/library/ubuntu:26.04@sha256:6a06111c3b0a8600feadb270aec771dc239bf407b25625f93f44ab00e11f4d3c

LABEL org.opencontainers.image.title="secure-image-converter reference test image" \
      org.opencontainers.image.description="PHP CLI, libvips, ImageMagick, webp, libavif, bubblewrap and util-linux for the tests of mfonte/secure-image-converter; not published" \
      org.opencontainers.image.source="https://github.com/mauriziofonte/php-secure-image-converter" \
      org.opencontainers.image.licenses="BSD-3-Clause"

ARG DEBIAN_FRONTEND=noninteractive

# libheif-plugin-aomenc gives vips and ImageMagick an AVIF encoder; the PHP
# extensions besides gd and imagick are those PHPUnit needs. The page below
# /usr/share/nginx/html stands for a web server's document root, which
# SandboxVisibilityTest checks no backend can see.
RUN apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 upgrade --yes \
    && apt-get -o Acquire::Retries=3 install --yes --no-install-recommends \
        bubblewrap \
        util-linux \
        libvips-tools \
        libheif-plugin-aomenc \
        webp \
        libavif-bin \
        imagemagick \
        php8.3-cli \
        php8.3-gd \
        php-imagick \
        php8.3-mbstring \
        php8.3-xml \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /usr/share/nginx/html \
    && echo 'private' > /usr/share/nginx/html/index.html \
    && php -r 'exit(extension_loaded("gd") && extension_loaded("imagick") ? 0 : 1);' \
    && vips --version \
    && cwebp -version \
    && gif2webp -version \
    && avifenc --version \
    && convert -version \
    && bwrap --version \
    && prlimit --version

# The tests run as this user: nothing in the image needs root at run time.
RUN useradd --create-home --uid 10001 --user-group --shell /usr/sbin/nologin tester

USER tester

WORKDIR /src

CMD ["php", "-v"]
