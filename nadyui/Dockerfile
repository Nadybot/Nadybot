FROM alpine:edge

# Mount the current folder to /build

# Create and enter a temporary directory
# so we do not overwrite package.json, package-lock.json or node_modules/
WORKDIR /build-tmp

RUN cp -r /build/* /build-tmp/ && \
    rm -rf /build-tmp/node_modules/ && \
    apk add --no-cache --virtual .node nodejs-current npm && \
    npm i && \
    npm run build && \
    mv dist /build/
