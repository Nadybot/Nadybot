#!/bin/bash

set -euo pipefail
cd "$(dirname "$(realpath "$0")")" || exit

case $# in
0)
	set +e
	while : ; do
		php -f main.php ./conf/config.php
		[ $? -eq 10 ] && break
	done
	set -e
;;
1)
	param=$(tr '[:upper:]' '[:lower:]' <<<"$1")
	if [ "$param" = "--list" ]; then
		for i in ./conf/*.php; do
			i=$(basename "$i" .php)
			if [ "$i" != "config.template" ]; then
				echo "      $i"
			fi
		done
	else
		if [ "$1" = "config.template" ]
		then
			echo "Error! '$1' is not allowed!"
		else
			set +e
			while : ; do
				php -f main.php "./conf/$param.php"
				[ $? -eq 10 ] && break
			done
			set -e
		fi
	fi
;;
*)
	echo "Error! Invalid parameter count!"
	echo "    Either use 'chatbot.sh' for standard"
	echo "    or use 'chatbot.sh <name>' for specific"
;;
esac
