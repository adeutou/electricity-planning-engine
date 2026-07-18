# Image de développement / démonstration : PHP CLI + `artisan serve`.
# Volontairement simple (pas de PHP-FPM/nginx, pas de multi-stage) — ce
# projet est une démonstration d'architecture, pas un déploiement de
# production. Pour aller plus loin : Octane (voir README, section
# "Pistes d'amélioration") remplacerait `artisan serve` par un serveur
# applicatif persistant (Swoole/RoadRunner) nettement plus performant.

FROM php:8.3-cli-alpine

RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql zip bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --no-progress --prefer-dist \
    && cp -n .env.example .env \
    && php artisan key:generate --force

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
