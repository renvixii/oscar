FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends smbclient \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/html
