#!/bin/sh
set -euo pipefail
if [ "$#" -ne 1 ]; then
  echo "Syntax: $0 <release>"
  exit 1
fi
export BUILDAH_LAYERS=true
buildah bud -t build:22.04 -f Dockerfile-ubuntu-22.04
podman run \
  --rm \
  -it \
  --security-opt label=disable \
  --userns keep-id \
  --user $(id -u):$(id -g) \
  -v "$PWD/transfer:/data" \
build:22.04 "$1"
