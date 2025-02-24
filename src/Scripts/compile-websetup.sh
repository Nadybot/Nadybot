#!/usr/bin/env bash

set -euo pipefail

BASE_DIR=$( dirname -- "$( dirname -- "$(cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd)" )" )
WEBSETUP_DIR="${BASE_DIR}/src/websetup"
HTML_DIR="${BASE_DIR}/src/Core/Modules/SETUP/html"

cd "${WEBSETUP_DIR}"

npm i --prefer-offline --no-audit
npm run build
cd "${HTML_DIR}"
find . ! -name 'api.json' ! -name '[0-9]*.html'  -type f -exec rm -f '{}' +
find . ! -name 'api.json' ! -name '[0-9]*.html'  ! -name . -type d -exec rmdir '{}' +
cp -r "${WEBSETUP_DIR}/dist"/* .
