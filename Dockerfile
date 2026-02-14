FROM quay.io/nadyita/alpine:3.20
ARG VERSION

LABEL maintainer="nadyita@hodorraid.org" \
      description="self-sustaining docker image to run latest Nadybot" \
      org.opencontainers.image.source="https://github.com/Nadybot/Nadybot"

ENTRYPOINT ["/sbin/tini", "-g", "--"]

CMD ["/nadybot/docker-entrypoint.sh"]

RUN apk --no-cache add \
    php82-cli \
    php82-sqlite3 \
    php82-phar \
    php82-iconv \
    php82-curl \
    php82-sockets \
    php82-pdo \
    php82-pdo_sqlite \
    php82-pdo_mysql \
    php82-mbstring \
    php82-ctype \
    php82-bcmath \
    php82-json \
    php82-posix \
    php82-simplexml \
    php82-dom \
    php82-gmp \
    php82-pcntl \
    php82-zip \
    php82-opcache \
    php82-fileinfo \
    php82-tokenizer \
    php82-intl \
    php82-sodium \
    tini \
    jemalloc \
    libuv \
    && \
    adduser -h /nadybot -s /bin/false -D -H nadybot

RUN apk --no-cache add \
        musl-dev \
        php82-dev \
        php82-pear \
        make \
        gcc \
        libuv-dev \
    && \
    pecl82 channel-update pecl.php.net && \
    pecl82 install channel://pecl.php.net/uv-0.3.0 && \
    strip /usr/lib/php82/modules/uv.so && \
    rm -rf /tmp/pear && \
    echo 'extension=uv.so' > /etc/php82/conf.d/02_uv.ini && \
    apk del --no-cache \
        musl-dev \
        php82-dev \
        php82-pear \
        make \
        gcc \
        libuv-dev

COPY --chown=nadybot:nadybot . /nadybot

ENV LD_PRELOAD=libjemalloc.so.2

RUN wget -O /usr/bin/composer https://getcomposer.org/composer-2.phar && \
    apk --no-cache add \
        sudo \
        jq \
        git \
    && \
    cd /nadybot && \
    sudo -u nadybot mkdir -p data/db cache && \
    sudo -u nadybot php82 /usr/bin/composer install --no-dev --no-interaction --no-progress -q && \
    sudo -u nadybot php82 /usr/bin/composer dumpautoload --no-dev --optimize --no-interaction 2>&1 | grep -v "/20[0-9]\{12\}_.*autoload" && \
    sudo -u nadybot php82 /usr/bin/composer clear-cache -q && \
    rm -f /usr/bin/composer && \
    jq 'del(.monolog.handlers.logs)|.monolog.formatters.console.options.format="[%level_name%] %message% %context% %extra%\n"|.monolog.formatters.console += {"calls": {"includeStacktraces": {"include" :true}}}' conf/logging.json > conf/logging.json.2 && \
    mv conf/logging.json.2 conf/logging.json && \
    chown nadybot:nadybot conf/logging.json && \
    apk del --no-cache sudo git && \
    if [ "x${VERSION}" != "x" ]; then \
        sed -i -e "s/public const VERSION = \"[^\"]*\";/public const VERSION = \"${VERSION:-4.0}\";/g" src/Core/BotRunner.php; \
    fi && \
    sed -i -e 's/memory_limit = 128M/memory_limit = 192M/' /etc/php82/php.ini


USER nadybot

WORKDIR /nadybot
