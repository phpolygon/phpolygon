# Pinned Alpine version — ensures identical FreeType/libgd across all runs.
# Only bump this deliberately when you want to regenerate all font snapshots.
FROM alpine:3.22

RUN apk add --no-cache \
    php84 php84-gd php84-mbstring php84-phar php84-dom \
    php84-xml php84-xmlwriter php84-tokenizer php84-openssl \
    php84-ctype \
    freetype libpng libjpeg-turbo \
    git unzip \
    && ln -s /usr/bin/php84 /usr/bin/php

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
