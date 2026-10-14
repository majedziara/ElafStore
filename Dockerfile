FROM php:8.4-cli

RUN apt-get update && apt-get install -y
git unzip libzip-dev libicu-dev
&& docker-php-ext-install pdo_mysql bcmath intl zip
&& apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN mkdir -p storage/framework/cache
storage/framework/sessions
storage/framework/views
storage/logs
bootstrap/cache
&& chmod -R 775 storage bootstrap/cache

RUN php artisan package:discover --ansi

EXPOSE 10000

CMD ["sh", "-c", "php artisan migrate --no-interaction && php artisan db:seed --class=LocalSandboxSeeder --no-interaction && php artisan serve --host=0.0.0.0 --port=10000"]
