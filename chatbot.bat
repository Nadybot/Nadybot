@echo off
title Nadybot
SET PHP_INI_SCAN_DIR=%CD%
start php -f main.php ./conf/config.php