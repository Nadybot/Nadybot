<?php declare(strict_types=1);

use function Safe\json_encode;

require 'vendor/autoload.php';

$runner = new Nadybot\Api\ApiSpecGenerator();
$runner->loadClasses();
$pathMapping = $runner->getPathMapping();
$spec = $runner->getSpec($pathMapping);
$order = [
	'get' => 1,
	'post' => 2,
	'put' => 3,
	'patch' => 4,
	'delete' => 5,
];
foreach ($spec['paths'] as $path => &$data) {
	uksort(
		$data,
		static function (string $a, string $b) use ($order): int {
			return ($order[$a] ?? 6) <=> ($order[$b] ?? 6);
		}
	);
}

echo(json_encode($spec, \JSON_UNESCAPED_SLASHES|\JSON_PRETTY_PRINT));
