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
# and GIFs) takes over an hour: run it on its own with --group slow. Add
# --user 65534:65534 -e HOME=/tmp to run as an unprivileged user, as a
# deployment would.
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
FROM mirror.gcr.io/library/ubuntu:24.04@sha256:fe81560990fae86dc4743202b2cb4d3df98d0bcf949173cecef15ce1127ef852

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

WORKDIR /src

CMD ["php", "-v"]
