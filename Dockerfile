FROM php:8.5-cli

WORKDIR /app

COPY composer.json .
COPY vendor/ vendor/
COPY app/ app/

CMD ["php", "app/main.php"]
