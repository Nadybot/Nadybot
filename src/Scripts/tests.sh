#!/usr/bin/env bash

psalmCheck() {
  if [ -e "${BINDIR}/psalm.phar" ]; then
    OUTPUT=$("${BINDIR}/psalm.phar" --show-info=true --no-progress --output-format=pylint 2>&1)
  else
    OUTPUT=$("${VENDOR}/vimeo/psalm/psalm" --show-info=true --no-progress --output-format=pylint 2>&1)
  fi
  if [ -n "$OUTPUT" ]; then
    echo "$OUTPUT"
    exit 1
  fi
}

phpCsFixer() {
  if [ -z "$CHANGED_FILES" ]; then
    exit 0
  fi

  if [ ! -e "${BINDIR}/php-cs-fixer.phar" ]; then
    exit 0
  fi
  if ! grep -qE "^(\\.php-cs-fixer(\\.dist)?\\.php|composer\\.lock)$" <<< "${CHANGED_FILES}"; then
    EXTRA_ARGS=$(printf -- '--path-mode=intersection\n--\n%s' "${CHANGED_FILES}" | grep -P '^src')
  else
    EXTRA_ARGS=''
  fi
  OUTPUT=$(php -dopcache.enable_cli=1 "${BINDIR}/php-cs-fixer.phar" fix --config=.php-cs-fixer.dist.php -v --dry-run --stop-on-violation --using-cache=no ${EXTRA_ARGS} 2>&1)
  if [ $? -ne 0 ]; then
    echo "$OUTPUT"
    exit 1
  fi
}

phpStanCheck() {
  OUTPUT=$(php -dopcache.enable_cli=1 -d memory_limit=2G "${VENDOR}/phpstan/phpstan/phpstan.phar" --no-progress -n --no-ansi analyse --error-format raw 2>&1)
  if [ $? -ne 0 ]; then
    echo "$OUTPUT"
    exit 1
  fi
}

phpCsCheck() {
  if [ -n "$CHANGED_FILES" ]; then
    EXTRA_ARGS=$(grep -P '^src' <<< "${CHANGED_FILES}")
    if [ -n "${EXTRA_ARGS}" ]; then
      php -dopcache.enable_cli=1 "${BINDIR}/phpcs" --cache --ignore=vendor --ignore=Stubs ${EXTRA_ARGS} -q --report=emacs
    else
      exit 0
    fi
  fi
}

magoCheck() {
  OUTPUT=$("${BINDIR}/mago" lint 2>&1)
  if [ $? -ne 0 ]; then
    echo "$OUTPUT"
    exit 1
  fi
}

valeCheck() {
  if command -v vale &> /dev/null; then
    if [ -n "${CHANGED_FILES}" ]; then
      CHANGED_FILES=$(grep -P '^src/' <<<"${CHANGED_FILES}")
    else
      CHANGED_FILES="src"
    fi
    OUTPUT=$(vale ${CHANGED_FILES} 2>&1)
    if [ $? -ne 0 ]; then
      echo "$OUTPUT"
      exit 1
    fi
  fi
}

spectralCheck() {
  if command -v spectral &> /dev/null; then
    OUTPUT=$(spectral lint -F hint html/api.json)
    if [ $? -ne 0 ]; then
      echo "$OUTPUT"
      exit 1
    fi
  fi
}

codespellCheck() {
  if [ -n "$CHANGED_FILES" ]; then
    codespell ${CHANGED_FILES}
  fi
}

export CHANGED_FILES=$(git diff --cached --name-only --diff-filter=ACMRTUXB | grep -v var_dump.yml | grep -v tests.sh)

if [ -n "$CHANGED_FILES" ] && grep -i -r -n -m 1 -s var_dump ${CHANGED_FILES}; then
  exit 1
fi

if [ -n "$CHANGED_FILES" ] && grep -i -r -n -m 1 -s -P "\\\\{[^,]+\\};" ${CHANGED_FILES}; then
  exit 1
fi

export VENDOR=$(composer config vendor-dir)
export BINDIR=$(composer config bin-dir)

declare -a tasks
declare -a results

tasks+=( psalmCheck )
tasks+=( phpStanCheck )
tasks+=( phpCsCheck )
tasks+=( codespellCheck )
tasks+=( phpCsFixer )
tasks+=( magoCheck )
tasks+=( spectralCheck )
tasks+=( valeCheck )

for task in ${tasks[@]}; do
  $task &
done

for task in ${tasks[@]}; do
  wait -n
  results+=( $? )
done

for result in ${results[@]}; do
  if [ $result -ne 0 ]; then
    exit 1
  fi
done
exit 0
