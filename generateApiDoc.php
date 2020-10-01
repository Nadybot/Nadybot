<?php

require 'vendor/autoload.php';

$runner = new Nadybot\Api\ApiSpecGenerator();
$runner->loadClasses();
$pathMapping = $runner->getPathMapping();
$spec = $runner->getSpec($pathMapping);
echo(
	preg_replace_callback(
		"/^((?:    )+)/m",
		function(array $matches): string {
			return str_repeat("\t", strlen($matches[1]) / 4);
		},
		json_encode($spec, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)
	)
);
