# webcam-portal sul VPS: PHP 8.2 + Apache, radice del sito in public/.
# MariaDB è esterno (quello del VPS); i dati stanno nel volume /data.
FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd exif \
 && apt-get purge -y --auto-remove libjpeg62-turbo-dev libpng-dev libfreetype6-dev \
 && apt-get install -y --no-install-recommends libjpeg62-turbo libpng16-16 libfreetype6 ffmpeg \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers remoteip

COPY docker/php.ini /usr/local/etc/php/conf.d/webcam-portal.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/webcam-portal
COPY --chown=www-data:www-data . .
RUN rm -rf storage/* .git \
 && mkdir -p /data && chown www-data:www-data /data

ENV STORAGE_DIR=/data \
    TRUST_PROXY=true

VOLUME ["/data"]
EXPOSE 80
