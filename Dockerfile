FROM quay.io/nadyita/alpine:3.15
ARG VERSION

LABEL maintainer="nadyita@hodorraid.org" \
      description="self-sustaining docker image to run latest Nadybot" \
      org.opencontainers.image.source="https://github.com/Nadybot/Nadybot"

ENTRYPOINT ["/sbin/tini", "-g", "--"]

CMD ["/nadybot/docker-entrypoint.sh"]

RUN apk --no-cache add \
    php8-cli \
    php8-sqlite3 \
    php8-phar \
    php8-curl \
    php8-sockets \
    php8-pdo \
    php8-pdo_sqlite \
    php8-pdo_mysql \
    php8-mbstring \
    php8-ctype \
    php8-bcmath \
    php8-json \
    php8-posix \
    php8-simplexml \
    php8-dom \
    php8-gmp \
    php8-pcntl \
    php8-zip \
    php8-opcache \
    php8-fileinfo \
	tini \
    && \
    adduser -h /nadybot -s /bin/false -D -H nadybot

COPY --chown=nadybot:nadybot . /nadybot

RUN wget -O /usr/bin/composer https://getcomposer.org/composer-2.phar && \
    apk --no-cache add \
        sudo \
        jq \
    && \
    cd /nadybot && \
    sudo -u nadybot mkdir -p data/db cache && \
    sudo -u nadybot php8 /usr/bin/composer install --no-dev --no-interaction --no-progress -q && \
    sudo -u nadybot php8 /usr/bin/composer dumpautoload --no-dev --optimize --no-interaction 2>&1 | grep -v "/20[0-9]\{12\}_.*autoload" && \
    sudo -u nadybot php8 /usr/bin/composer clear-cache -q && \
    rm -f /usr/bin/composer && \
    jq 'del(.monolog.handlers.logs)' conf/logging.json > conf/logging.json.2 && \
    mv conf/logging.json.2 conf/logging.json && \
    chown nadybot:nadybot conf/logging.json && \
    apk del --no-cache sudo jq && \
    if [ "x${VERSION}" != "x" ]; then \
        sed -i -e "s/public const VERSION = \"[^\"]*\";/public const VERSION = \"${VERSION:-4.0}\";/g" src/Core/BotRunner.php; \
    fi

USER nadybot

WORKDIR /nadybot
