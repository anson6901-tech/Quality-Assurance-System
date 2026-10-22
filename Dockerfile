FROM node:24-bookworm-slim AS node
FROM composer:2 AS composer

FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libatomic1 \
        libonig-dev \
        libxml2-dev \
        unzip \
    && docker-php-ext-install dom mbstring pcntl pdo_mysql simplexml xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*

# Report PDFs may be up to 10 MB (app validation); PHP's 2 MB default would reject them.
RUN printf 'upload_max_filesize=12M\npost_max_size=16M\nmax_file_uploads=50\n' > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www/html
