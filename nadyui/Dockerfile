FROM alpine:edge

WORKDIR /build

COPY package.json package-lock.json ./

RUN apk add --no-cache --virtual .node nodejs-current npm && \
    npm i

COPY . .

RUN npm run build && \
    mv dist /dist && \
    adduser -h /dist -s /bin/false -D -H nadyui && \
    chown -R nadyui:nadyui /dist && \
    apk del .node && \
    apk add --no-cache darkhttpd

USER nadyui

CMD ["darkhttpd", "/dist"]
