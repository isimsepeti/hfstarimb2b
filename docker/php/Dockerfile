# cPanel/CloudLinux ortamına yakın: Apache + .htaccess + PHP 8.4
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libicu-dev unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd intl \
    && a2enmod rewrite headers expires deflate \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/zz-hfs.ini
