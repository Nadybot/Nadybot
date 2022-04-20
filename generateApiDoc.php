<?php

require 'vendor/autoload.php';

$runner = new Nadybot\Api\ApiSpecGenerator();
$runner->loadClasses();
$pathMapping = $runner->getPathMapping();
$spec = $runner->getSpec($pathMapping);
$order = [
	"get" => 1 ,
	"post" => 2 ,
	"put" => 3,
	"patch" => 4,
	"delete" => 5,
];
foreach ($spec["paths"] as $path => &$data) {
	uksort(
		$data,
		function (string $a, string $b) use ($order): int {
			return ($order[$a] ?? 6) <=> ($order[$b] ?? 6);
		}
	);
}

echo(
	preg_replace_callback(
		"/^((?:    )+)/m",
		function(array $matches): string {
			return str_repeat("\t", (int)floor(strlen($matches[1]) / 4));
		},
		json_encode($spec, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)
	)
);
