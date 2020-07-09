<?php

$php_file = "main.php";
$config_file = $argv[1];

// Handle the shutdown command
while (true) {
	system(PHP_BINARY . " -f $php_file -- $config_file", $returnVar);
	if ($returnVar == 10) {
		break;
	}
}
