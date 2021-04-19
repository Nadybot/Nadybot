<?php

/*
xdebug_start_code_coverage(XDEBUG_CC_UNUSED);
xdebug_set_filter(
	XDEBUG_FILTER_CODE_COVERAGE,
	defined("XDEBUG_PATH_WHITELIST") ? XDEBUG_PATH_WHITELIST : XDEBUG_PATH_INCLUDE,
	[ __DIR__ . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR ]
);
*/
require 'vendor/autoload.php';

$runner = new Nadybot\Core\BotRunner($argv);
$runner->run();
/*
$coverage = xdebug_get_code_coverage();
unlink("/tmp/called2.txt");
ksort($coverage);
$fh = fopen("/tmp/called2.txt", "w");
foreach ($coverage as $file => $lines) {
	foreach ($lines as $line => $covered) {
		if ($covered !== -1) {
			fputs($fh, "{$file}:{$line}\n");
		}
	}
}
fclose($fh);
*/