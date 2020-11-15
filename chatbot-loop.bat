@echo off
title Nadybot
SET PHP_INI_SCAN_DIR=%CD%
:loop
php -f main.php ./conf/config.php
goto loop