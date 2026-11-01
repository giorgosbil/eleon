FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libxml2-dev libcurl4-openssl-dev libzip-dev unzip curl ca-certificates gosu poppler-utils hunspell hunspell-el hunspell-en-us \
    && docker-php-ext-install pdo_mysql mbstring dom pcntl zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/ecw
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress
COPY . .
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod headers >/dev/null
COPY docker/php.ini /usr/local/etc/php/conf.d/ecw.ini
RUN mkdir -p storage/attachments cache/htmlpurifier logs /run/ecw && chown -R www-data:www-data storage cache logs /run/ecw \
    && chmod +x docker/entrypoint.sh \
    && printf '<?php header("Location: /other_things/web/");' > /var/www/html/index.php
ENTRYPOINT ["/opt/ecw/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
