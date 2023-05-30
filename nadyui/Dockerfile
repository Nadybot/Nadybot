FROM alpine:edge

# Mount the current folder to /build

# Create and enter a temporary directory
# so we do not overwrite package.json, package-lock.json or node_modules/
WORKDIR /tmp/build

RUN cp -r /build/* /tmp/build && \
    rm -rf /tmp/build/node_modules/ && \
    apk add --no-cache --virtual .node nodejs npm git && \
    npm i && \
    npm run build && \
    rm -rf /build/dist && \
    mv dist /build/
