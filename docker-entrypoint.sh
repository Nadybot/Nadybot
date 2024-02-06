#!/bin/ash
# shellcheck shell=dash

errorMessage() {
	echo "$*"
	exit 1
}

checkBool() {
	value=$(echo "$1" | tr '[:upper:]' '[:lower:]')
	if [ "$value" = "1" ] || [ "$value" = "true" ] || [ "$value" = "yes" ]; then
		echo "$2"
	else
		echo "$3"
	fi
}

[ -z "$CONFIG_LOGIN" ] && errorMessage "You have to specify the login by setting \$CONFIG_LOGIN"
[ -z "$CONFIG_PASSWORD" ] && errorMessage "You have to specify the password by setting \$CONFIG_PASSWORD"
[ -z "$CONFIG_BOTNAME" ] && errorMessage "You have to specify the name of the bot by setting \$CONFIG_BOTNAME"
[ -z "$CONFIG_SUPERADMIN" ] && errorMessage "You have to specify the name of the Superadmin by setting \$CONFIG_SUPERADMIN"
[ -z "$CONFIG_DB_TYPE" ] && errorMessage "You have to specify the database type by setting \$CONFIG_DB_TYPE to sqlite or mysql"
[ -z "$CONFIG_DB_NAME" ] && errorMessage "You have to specify the name of the database by setting \$CONFIG_DB_NAME"
[ -z "$CONFIG_DB_HOST" ] && errorMessage "You have to specify the host/socket/directory of the database by setting \$CONFIG_DB_HOST"
[ -n "$CONFIG_LOG_LEVEL" ] && ( echo "$CONFIG_LOG_LEVEL" | grep -q -v -i -E '^(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)$' ) && errorMessage "You have specified an invalid \$CONFIG_LOG_LEVEL. Allowed values are DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT and EMERGENCY."
[ -z "$CONFIG_AUTO_UNFREEZE_LOGIN" ] || CONFIG_AUTO_UNFREEZE_LOGIN="\"${CONFIG_AUTO_UNFREEZE_LOGIN}\""
[ -z "$CONFIG_AUTO_UNFREEZE_PASSWORD" ] || CONFIG_AUTO_UNFREEZE_PASSWORD="\"${CONFIG_AUTO_UNFREEZE_PASSWORD}\""

cd /nadybot || exit
EXTRA_SETTINGS=$(set | grep '^CONFIG_SETTING_' | sed -e 's/^CONFIG_SETTING_//g'| while read -r SETTING; do
  KEY=$(echo "$SETTING" | cut -d '=' -f 1 | tr '[:upper:]' '[:lower:]')
  VALUE=$(echo "$SETTING" | cut -d '=' -f 2-)
  echo "'$KEY' => $VALUE,"
done)
cat > /tmp/config.php << DONE
<?php declare(strict_types=1);

\$vars = [];
\$vars['login']      = "$CONFIG_LOGIN";
\$vars['password']   = "$CONFIG_PASSWORD";
\$vars['name']       = "$CONFIG_BOTNAME";
\$vars['my_guild']   = "${CONFIG_ORG:-}";
\$vars['org_id']   = ${CONFIG_ORG_ID:-null};
\$vars['dimension']  = ${CONFIG_DIMENSION:-5};
\$vars['SuperAdmin'] = ["$(echo "${CONFIG_SUPERADMIN}" | sed -e 's/[, ]\+/", "/g')"];
\$vars['DB Type'] = "$CONFIG_DB_TYPE";
\$vars['DB Name'] = "$CONFIG_DB_NAME";
\$vars['DB Host'] = "$CONFIG_DB_HOST";
\$vars['DB username'] = "$CONFIG_DB_USER";
\$vars['DB password'] = "$CONFIG_DB_PASS";
\$vars['show_aoml_markup'] = $(checkBool "${CONFIG_SHOW_AOML_MARKUP:-0}" 1 0);
\$vars['cachefolder'] = "${CONFIG_CACHEFOLDER:-./cache/}";
\$vars['default_module_status'] = ${CONFIG_DEFAULT_MODULE_STATUS:-0};
\$vars['use_proxy'] = $(checkBool "${CONFIG_USE_PROXY:-0}" 1 0);
\$vars['enable_console_client'] = $(checkBool "${CONFIG_ENABLE_CONSOLE:-0}" 1 0);
\$vars['enable_package_module'] = $(checkBool "${CONFIG_ENABLE_PACKAGE_MODULE:-0}" 1 0);
\$vars['proxy_server'] = "${CONFIG_PROXY_SERVER:-127.0.0.1}";
\$vars['proxy_port'] = ${CONFIG_PROXY_PORT:-9993};
\$vars['API Port'] = ${CONFIG_API_PORT:-5250};
\$vars['auto_unfreeze'] = $(checkBool "${CONFIG_AUTO_UNFREEZE:-false}" true false);
\$vars['auto_unfreeze_login'] = ${CONFIG_AUTO_UNFREEZE_LOGIN:-null};
\$vars['auto_unfreeze_password'] = ${CONFIG_AUTO_UNFREEZE_PASSWORD:-null};

\$vars['module_load_paths'] = [
	'./src/Modules',
	'./extras',
];
\$vars['settings'] = [
${EXTRA_SETTINGS}
];
\$vars['amqp_server'] = "${CONFIG_AMQP_SERVER}";
\$vars['amqp_port'] = ${CONFIG_AMQP_PORT:-5672};
\$vars['amqp_user'] = "${CONFIG_AMQP_USER}";
\$vars['amqp_password'] = "${CONFIG_AMQP_PASSWORD}";
\$vars['amqp_vhost'] = "${CONFIG_AMQP_VHOST:-/}";
DONE

LOG_CONFIG=$(cat conf/logging.json | sed -e "s/\"\*\": \"notice\"/\"*\": \"${CONFIG_LOG_LEVEL:-notice}\"/")
echo $LOG_CONFIG > /tmp/logging.json
ERROR_MSG=$(set | grep '^CONFIG_LOGGING_' | sed -e 's/^CONFIG_LOGGING_/.monolog./g'| while read -r SETTING; do
  KEY=$(echo "$SETTING" | cut -d '=' -f 1 | sed -e 's/_/./g')
  eval VALUE=$(echo "$SETTING" | cut -d '=' -f 2-)
  if ! jq --argjson foo ${VALUE} --help &>/dev/null; then
    echo "${VALUE} is not a valid JSON value"
    exit 1
  fi
  LOG_CONFIG=$(jq --argjson foo ${VALUE} "${KEY}=\$foo" /tmp/logging.json)
  echo $LOG_CONFIG > /tmp/logging.json
done)
if [ $? = 1 ]; then
  echo "${ERROR_MSG}"
  exit 1
fi
if [ -e /proxy/aochatproxy ] \
	&& [ "$(checkBool "${CONFIG_USE_PROXY:-0}" 1 0)" = "1" ] \
	&& [ -n "${PROXY_CHARNAME_1:-}" ] \
	&& [ -n "${PROXY_USERNAME_1:-}" ] \
	&& [ -n "${PROXY_PASSWORD_1:-}" ]; then
	FC_PORT=7105
	if [ "${CONFIG_DIMENSION:-5}" = "4" ]; then
		FC_PORT=7109
	elif [ "${CONFIG_DIMENSION:-5}" = "6" ]; then
		FC_PORT=7106
	fi
	cat > /tmp/config.json <<-DONE
		{
		    "rust_log": "${PROXY_LOGLEVEL:-info}",
		    "port_number": ${CONFIG_PROXY_PORT:-9993},
		    "server_address": "chat.d1.funcom.com:${FC_PORT}",
		    "spam_bot_support": $(checkBool "${PROXY_SPAM_BOT_SUPPORT:-0}" true false),
		    "send_tells_over_main": $(checkBool "${PROXY_SEND_TELLS_OVER_MAIN:-1}" true false),
		    "relay_worker_tells": $(checkBool "${PROXY_RELAY_WORKER_TELLS:-1}" true false),
		    "auto_unfreeze_accounts": $(checkBool "${CONFIG_AUTO_UNFREEZE:-false}" true false),
		    "accounts": [
	DONE
	SUFFIX=1
	while [ -n "$(eval echo "\${PROXY_CHARNAME_$SUFFIX:-}")" ]; do
		if [ -n "$(eval echo "\${PROXY_USERNAME_$SUFFIX:-}")" ]; then
			LASTUSER=$(eval echo "\${PROXY_USERNAME_$SUFFIX:-}")
		fi
		if [ -n "$(eval echo "\${PROXY_PASSWORD_$SUFFIX:-}")" ]; then
			LASTPASS=$(eval echo "\${PROXY_PASSWORD_$SUFFIX:-}")
		fi
		if [ -n "$(eval echo "\${PROXY_UNFREEZE_USERNAME_$SUFFIX:-}")" ]; then
			LASTUNFREEZEUSER=$(eval echo "\${PROXY_UNFREEZE_USERNAME_$SUFFIX:-}")
		fi
		if [ -n "$(eval echo "\${PROXY_UNFREEZE_PASSWORD_$SUFFIX:-}")" ]; then
			LASTUNFREEZEPASS=$(eval echo "\${PROXY_UNFREEZE_PASSWORD_$SUFFIX:-}")
		fi
		if [ "$SUFFIX" -gt 1 ]; then
			echo "        ," >> /tmp/config.json
		fi
		cat >> /tmp/config.json <<-END
			        {
			            "username": "${LASTUSER}",
			            "password": "${LASTPASS}",
			            "unfreeze_username": "${LASTUNFREEZEUSER:-$LASTUSER}",
			            "unfreeze_password": "${LASTUNFREEZEPASS:-$LASTPASS}",
			            "character": "$(eval echo "\${PROXY_CHARNAME_$SUFFIX:-}")"
			        }
		END
		SUFFIX=$((SUFFIX+1))
	done
	cat >> /tmp/config.json <<-DONE
		    ]
		}
	DONE

	cd /proxy || exit
	(/usr/bin/env RUST_BACKTRACE=full /proxy/aochatproxy /tmp/config.json 2>&1| stdbuf -i0 -o0 -e0 sed -e 's/^[^ ]* \([A-Z]*\) .*\]/[PROXY:\1]/') &
	cd /nadybot || exit
fi

PHP=$(which php82 php81 php8 php | head -n 1)
if [ -n "$CONFIG_JIT_BUFFER_SIZE" ]; then
	PHP_PARAMS="${PHP_PARAMS:-} -dopcache.enable_cli=1 -dopcache.jit_buffer_size=${JIT_BUFFER_SIZE} -dopcache.jit=1235"
fi

EXITCODE=255
while [ "$EXITCODE" -eq 255 ]; do
	trap "" TERM
	# shellcheck disable=SC2086
	"$PHP" ${PHP_PARAMS:-} -f main.php -- --log-config /tmp/logging.json /tmp/config.php "$@"
	EXITCODE=$?
	trap - TERM
done
exit $EXITCODE
