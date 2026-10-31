# Every backend with the packages of Ubuntu 24.04, for the "backends" test
# group (the CI job of that name installs the same packages). Minimal on
# purpose: LIB-9 turns it into the published reference image.
#
#   docker build --network host -t sic-backends tests/docker
#   docker run --rm --network host \
#     --security-opt seccomp=unconfined --security-opt apparmor=unconfined \
#     --security-opt systempaths=unconfined \
#     -v "$PWD:/src" -w /src -e SIC_EXPECTED_ISOLATION_LEVEL=sandbox \
#     -e SIC_EXPECTED_BACKENDS=vips,cwebp,gif2webp,avifenc,imagick,gd \
#     sic-backends php vendor/bin/phpunit --group backends --exclude-group slow
#
# The group "slow" (highest-effort AVIF of large screenshots and GIFs) takes
# over an hour: run it on its own with --group slow.
#
# Docker's default seccomp and AppArmor profiles forbid the user namespaces
# that bubblewrap needs: without the security options the tests run at the
# rlimit level and the sandbox tests skip. Install the Composer dependencies
# on the host first.
FROM mirror.gcr.io/library/ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# libheif-plugin-aomenc gives vips and ImageMagick an AVIF encoder. The page
# below /usr/share/nginx/html stands for a web server's document root, which
# SandboxVisibilityTest checks no backend can see.
RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
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
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /usr/share/nginx/html \
    && echo 'private' > /usr/share/nginx/html/index.html
