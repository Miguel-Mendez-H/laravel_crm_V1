FROM php:8.3-cli

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update && apt-get install -y --no-install-recommends git unzip default-mysql-client \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql mysqli zip gd intl bcmath exif pcntl opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
      echo "memory_limit=1G"; \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=1"; \
      echo "opcache.revalidate_freq=2"; \
      echo "realpath_cache_size=4096K"; \
      echo "realpath_cache_ttl=600"; \
    } > /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
