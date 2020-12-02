#!/bin/bash

case $# in
0)
	true
	while [ $? -ne 10 ]; do
		php -f main.php ./conf/config.php
	done
;;
1)
	param=`echo $1 | tr '[:upper:]' '[:lower:]'`
	if [ "$param" = "--list" ]
	then
		list=(`ls ./conf/ | grep -oP ".*(?=\\.php)"`)
		for i in ${!list[*]}
		do
			if [ "${list[$i]}" != "config.template" ]
			then
				echo "      ${list[$i]}"
			fi
		done
	else
		if [ "$1" = "config.template" ]
		then
			echo "Error! '$1' is not allowed!"
		else
			true
			while [ $? -ne 10 ]; do
				php -f main.php "./conf/$param.php"
			done
		fi
	fi
;;
*)
	echo "Error! Invalid parameter count!"
	echo "    Either use 'chatbot.sh' for standard"
	echo "    or use 'chatbot.sh <name>' for specific"
;;
esac
