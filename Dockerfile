FROM quay.io/nadyita/alpine:3.20
ARG VERSION

LABEL maintainer="nadyita@hodorraid.org" \
      description="self-sustaining docker image to run latest Nadybot" \
      org.opencontainers.image.source="https://github.com/Nadybot/Nadybot"

ENTRYPOINT ["/sbin/tini", "-g", "--"]

CMD ["/nadybot/docker-entrypoint.sh"]

RUN apk --no-cache add \
    php83-cli \
    php83-sqlite3 \
    php83-phar \
    php83-iconv \
    php83-curl \
    php83-sockets \
    php83-pdo \
    php83-pdo_sqlite \
    php83-pdo_mysql \
    php83-mbstring \
    php83-ctype \
    php83-bcmath \
    php83-json \
    php83-posix \
    php83-simplexml \
    php83-dom \
    php83-gmp \
    php83-pcntl \
    php83-zip \
    php83-opcache \
    php83-fileinfo \
    php83-tokenizer \
    php83-intl \
    php83-sodium \
    tini \
    jemalloc \
    libuv \
    && \
    adduser -h /nadybot -s /bin/false -D -H nadybot

RUN apk --no-cache add \
        musl-dev \
        php83-dev \
        php83-pear \
        make \
        gcc \
        libuv-dev \
    && \
    pecl82 channel-update pecl.php.net && \
    pecl82 install channel://pecl.php.net/uv-0.3.0 && \
    strip /usr/lib/php83/modules/uv.so && \
    rm -rf /tmp/pear && \
    echo 'extension=uv.so' > /etc/php83/conf.d/02_uv.ini && \
    apk del --no-cache \
        musl-dev \
        php83-dev \
        php83-pear \
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
    sudo -u nadybot php83 /usr/bin/composer install --no-dev --no-interaction --no-progress -q && \
    sudo -u nadybot php83 /usr/bin/composer dumpautoload --no-dev --optimize --no-interaction 2>&1 | grep -v "/20[0-9]\{12\}_.*autoload" && \
    sudo -u nadybot php83 /usr/bin/composer clear-cache -q && \
    rm -f /usr/bin/composer && \
    jq 'del(.monolog.handlers.logs)|.monolog.formatters.console.options.format="[%level_name%] %message% %context% %extra%\n"|.monolog.formatters.console += {"calls": {"includeStacktraces": {"include" :true}}}' conf/logging.json > conf/logging.json.2 && \
    mv conf/logging.json.2 conf/logging.json && \
    chown nadybot:nadybot conf/logging.json && \
    apk del --no-cache sudo git && \
    if [ "x${VERSION}" != "x" ]; then \
        sed -i -e "s/public const VERSION = \"[^\"]*\";/public const VERSION = \"${VERSION:-4.0}\";/g" src/Core/BotRunner.php; \
    fi && \
    sed -i -e 's/memory_limit = 128M/memory_limit = 192M/' /etc/php83/php.ini


USER nadybot

WORKDIR /nadybot
