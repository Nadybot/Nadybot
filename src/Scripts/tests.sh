#!/usr/bin/env bash

psalmCheck() {
  VENDOR=$(composer config vendor-dir)
  BINDIR=$(composer config bin-dir)
  if [ -e "${BINDIR}/psalm.phar" ]; then
    OUTPUT=$("${BINDIR}/psalm.phar" --show-info=true --no-progress --threads=6 --output-format=pylint)
  else
    OUTPUT=$("${VENDOR}/vimeo/psalm/psalm" --show-info=true --no-progress --threads=6 --output-format=pylint)
  fi
  if [ -n "$OUTPUT" ]; then
    echo "$OUTPUT"
    exit 1
  fi
}

phpCsFixer() {
  BINDIR=$(composer config bin-dir)
  if [ ! -e "${BINDIR}/php-cs-fixer.phar" ]; then
    exit 0
  fi
  if ! echo "$1" | grep -qE "^(\\.php-cs-fixer(\\.dist)?\\.php|composer\\.lock)$"; then EXTRA_ARGS=$(printf -- '--path-mode=intersection\n--\n%s' "$1"); else EXTRA_ARGS=''; fi
  OUTPUT=$(php -dopcache.enable_cli=1 "${BINDIR}/php-cs-fixer.phar" fix --config=.php-cs-fixer.dist.php -v --dry-run --stop-on-violation --using-cache=no ${EXTRA_ARGS} 2>&1)
  if [ $? -ne 0 ]; then
    echo "$OUTPUT"
    exit 1
  fi
}

CHANGED_FILES=$(git diff --cached --name-only --diff-filter=ACMRTUXB | grep -v var_dump.yml | grep -v tests.sh)

if [ -n "$CHANGED_FILES" ] && grep -i -r -n -m 1 -s var_dump ${CHANGED_FILES}; then
  exit 1
fi

if [ -n "$CHANGED_FILES" ] && grep -i -r -n -m 1 -s -P "\\\\{[^,]+\\};" ${CHANGED_FILES}; then
  exit 1
fi

VENDOR=$(composer config vendor-dir)
BINDIR=$(composer config bin-dir)
psalmCheck &
php81 -dopcache.enable_cli=1 -d memory_limit=2G "${VENDOR}/phpstan/phpstan/phpstan.phar" --no-progress -n --no-ansi analyse --error-format raw &
if [ -n "$CHANGED_FILES" ]; then
  php -dopcache.enable_cli=1 "${BINDIR}/phpcs" --cache --ignore=vendor --ignore=Stubs ${CHANGED_FILES} -q --report=emacs &
else
  true &
fi
if [ -n "$CHANGED_FILES" ]; then
  codespell ${CHANGED_FILES} &
else
  true &
fi
if [ -n "$CHANGED_FILES" ]; then
  phpCsFixer "${CHANGED_FILES}" &
else
  true &
fi
if command -v vale &> /dev/null; then
  if [ -n "${CHANGED_FILES}" ]; then
    CHANGED_FILES=$(grep -P '^src/' <<<"${CHANGED_FILES}")
  else
    CHANGED_FILES="src"
  fi
  vale ${CHANGED_FILES} &
else
  true &
fi

wait -n
RESULT_ONE=$?
wait -n
RESULT_TWO=$?
wait -n
RESULT_THREE=$?
wait -n
RESULT_FOUR=$?
wait -n
RESULT_FIVE=$?
wait -n
RESULT_SIX=$?

if [ "${RESULT_ONE}" -ne 0 ] || [ "${RESULT_TWO}" -ne 0 ] || [ "${RESULT_THREE}" -ne 0 ] || [ "${RESULT_FOUR}" -ne 0 ] || [ "${RESULT_FIVE}" -ne 0 ] || [ "${RESULT_SIX}" -ne 0 ]; then
    exit 1
fi
exit 0
