#!/usr/bin/env bash

set -euo pipefail

BASE_DIR=$( dirname -- "$( dirname -- "$(cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd)" )" )
NADYUI_DIR="${BASE_DIR}/nadyui"
HTML_DIR="${BASE_DIR}/html"

cd "${NADYUI_DIR}"

npm i
npm run build
cd dist
git rev-parse HEAD > _version
rm -f ../nadyui.zip
zip -r ../nadyui.zip .
if [ "$#" -ne 1 ]; then
  exit 0
fi

if [ "$1" == "install" ]; then
  cd "${HTML_DIR}"
  find . ! -name 'api.json' -type f -exec rm -f '{}' +
  find . ! -name 'api.json' ! -name . -type d -exec rmdir '{}' +
  unzip "${NADYUI_DIR}/nadyui.zip"
  rm -f "${NADYUI_DIR}/nadyui.zip"
fi
