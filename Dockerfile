FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libonig-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

COPY . /var/www/html

RUN php tools/install.php --check \
    && php tools/check-migrations.php \
    && php tools/check-translations.php \
    && php tests/translator.php \
    && chmod 755 docker/entrypoint.sh \
    && mkdir -p storage/lang

EXPOSE 80

ENTRYPOINT ["docker/entrypoint.sh"]
