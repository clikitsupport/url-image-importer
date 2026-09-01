FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# System toolchain plus the PHP runtime and extensions the plugin and its
# PHPUnit suite need (dom, libxml, simplexml, gd, fileinfo, mbstring, curl).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        php-cli \
        php-xml \
        php-mbstring \
        php-curl \
        php-gd \
    && rm -rf /var/lib/apt/lists/*

# Install Composer v2 into the image.
RUN curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php

# The Cloud Agent runs as the "ubuntu" user, which already exists in the
# ubuntu:24.04 base image (uid 1000).
USER ubuntu
