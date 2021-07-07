FROM quay.io/nadyita/alpine:latest
ARG VERSION

LABEL maintainer="nadyita@hodorraid.org" \
      description="self-sustaining docker image to run latest Nadybot" \
      org.opencontainers.image.source="https://github.com/Nadybot/Nadybot"

ENTRYPOINT ["/sbin/tini", "-g", "--"]

CMD ["/nadybot/docker-entrypoint.sh"]


RUN apk --no-cache --repository http://dl-3.alpinelinux.org/alpine/edge/community/ add \
    php7-cli \
    php7-sqlite3 \
    php7-iconv \
    php7-phar \
    php7-gmp \
    php7-curl \
    php7-sockets \
    php7-pdo \
    php7-pdo_sqlite \
    php7-pdo_mysql \
    php7-mbstring \
    php7-ctype \
    php7-bcmath \
    php7-json \
    php7-posix \
    php7-xml \
    php7-simplexml \
    php7-dom \
    php7-pcntl \
    php7-zip \
    php7-fileinfo \
	tini \
    && \
    adduser -h /nadybot -s /bin/false -D -H nadybot

COPY --chown=nadybot:nadybot . /nadybot

RUN apk --no-cache add composer && \
    cd /nadybot && \
    composer install --no-dev --no-suggest && \
    rm -rf "$(composer config vendor-dir)/niktux/addendum/Tests" && \
    rm -f "$(composer config vendor-dir)/niktux/addendum/composer.phar" && \
    composer dumpautoload --no-dev --optimize && \
    composer clear-cache && \
    chown -R nadybot:nadybot vendor && \
    apk del --no-cache composer && \
    sed -i -e '/<appender_ref ref="defaultFileAppender" \/>/d' conf/log4php.xml && \
    if [ "x${VERSION}" != "x" ]; then \
        sed -i -e "s/public const VERSION = \"[^\"]*\";/public const VERSION = \"${VERSION:-4.0}\";/g" src/Core/BotRunner.php; \
    fi

USER nadybot

WORKDIR /nadybot