#!/bin/sh

errorMessage() {
  echo "$*"
  exit 1
}

[ -z "$CONFIG_LOGIN" ] && errorMessage 'You have to specify the login by setting $CONFIG_LOGIN'
[ -z "$CONFIG_PASSWORD" ] && errorMessage 'You have to specify the password by setting $CONFIG_PASSWORD'
[ -z "$CONFIG_BOTNAME" ] && errorMessage 'You have to specify the name of the bot by setting $CONFIG_BOTNAME'
[ -z "$CONFIG_SUPERADMIN" ] && errorMessage 'You have to specify the name of the Superadmin by setting $CONFIG_SUPERADMIN'
[ -z "$CONFIG_DB_TYPE" ] && errorMessage 'You have to specify the database type by setting $CONFIG_DB_TYPE to sqlite or mysql'
[ -z "$CONFIG_DB_NAME" ] && errorMessage 'You have to specify the name of the database by setting $CONFIG_DB_NAME'
[ -z "$CONFIG_DB_HOST" ] && errorMessage 'You have to specify the host/socket/directory of the database by setting $CONFIG_DB_HOST'
[ -n "$CONFIG_LOG_LEVEL" ] && ( echo "$CONFIG_LOG_LEVEL" | grep -q -v -E '^(TRACE|DEBUG|INFO|WARN|ERROR|FATAL)$' ) && errorMessage 'You have specified an invalid $CONFIG_LOG_LEVEL. Allowed values are TRACE, DEBUG, INFO, WARN, ERROR and FATAL.'

cd /budabot
cat > conf/config.php << DONE
<?php
  \$vars['login']      = "$CONFIG_LOGIN";
  \$vars['password']   = "$CONFIG_PASSWORD";
  \$vars['name']       = "$CONFIG_BOTNAME";
  \$vars['my_guild']   = "${CONFIG_ORG:-}";
  \$vars['dimension']  = ${CONFIG_DIMENSION:-5};
  \$vars['SuperAdmin'] = "$CONFIG_SUPERADMIN";
  \$vars['DB Type'] = "$CONFIG_DB_TYPE";		// What type of database should be used? ('sqlite' or 'mysql')
  \$vars['DB Name'] = "$CONFIG_DB_NAME";	// Database name
  \$vars['DB Host'] = "$CONFIG_DB_HOST";		// Hostname or file location
  \$vars['DB username'] = "$CONFIG_DB_USER";			// MySQL username
  \$vars['DB password'] = "$CONFIG_DB_PASS";			// MySQL password
  // Show AOML markup in logs/console? 1 for enabled, 0 for disabled.
  \$vars['show_aoml_markup'] = ${CONFIG_SHOW_AOML_MARKUP:-0};
  // Cache folder for storing organization XML files.
  \$vars['cachefolder'] = "${CONFIG_CACHEFOLDER:-./cache/}";
  // Default status for new modules? 1 for enabled, 0 for disabled.
  \$vars['default_module_status'] = ${CONFIG_DEFAULT_MODULE_STATUS:-0};
  // Use AO Chat Proxy? 1 for enabled, 0 for disabled.
  \$vars['use_proxy'] = ${CONFIG_USE_PROXY:-0};
  \$vars['proxy_server'] = "${CONFIG_PROXY_SERVER:-127.0.0.1}";
  \$vars['proxy_port'] = ${CONFIG_PROXY_PORT:-9993};
  \$vars['API Port'] = ${CONFIG_API_PORT:-5250};
  // Define additional paths from where Budabot should load modules at startup
  \$vars['module_load_paths'] = [
    './src/Modules', './extras'
  ];
  \$vars['amqp_server'] = "${CONFIG_AMQP_SERVER}";
  \$vars['amqp_port'] = ${CONFIG_AMQP_PORT:-5672};
  \$vars['amqp_user'] = "${CONFIG_AMQP_USER}";
  \$vars['amqp_password'] = "${CONFIG_AMQP_PASSWORD}";
  \$vars['amqp_vhost'] = "${CONFIG_AMQP_VHOST:-/}";
  // This should only be enabled during development and only if you understand the implications
  // seen here: <http://code.google.com/p/budabot2/wiki/CompilingRunkit>
  define("USE_RUNKIT_CLASS_LOADING", false);
DONE

sed -i -e "s/<level value=\"INFO\"/<level value=\"${CONFIG_LOG_LEVEL:-INFO}\"/" conf/log4php.xml

exec php7 -f main.php -- conf/config.php "$@"
