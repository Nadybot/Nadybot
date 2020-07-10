FROM alpine:3.12

LABEL maintainer="nadyita@hodorraid.org" \
      description="self-sustaining docker image to run latest Budabot-Nady"

ENTRYPOINT ["/budabot/docker-entrypoint.sh"]

RUN apk --no-cache add \
    php7-cli \
    php7-sqlite3 \
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
    php7-openssl \
    php7-xml \
    php7-simplexml \
    php7-dom \
    php7-pcntl \
    && \
    adduser -h /budabot -s /bin/false -D -H budabot

COPY --chown=budabot:budabot . /budabot

RUN apk --no-cache add composer && \
    cd /budabot && \
    composer install --no-dev --no-suggest && \
    rm -rf "$(composer config vendor-dir)/niktux/addendum/Tests" && \
    composer dumpautoload --no-dev --optimize && \
    composer clear-cache && \
    chown -R budabot:budabot vendor && \
    apk del --no-cache composer && \
    sed -i -e '/<appender_ref ref="defaultFileAppender" \/>/d' conf/log4php.xml


USER budabot

WORKDIR /budabot
