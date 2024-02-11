<?php declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use function Safe\{file_get_contents, preg_match_all};

function checkCall(string $file, string $mask, string $params): void {
	if (!preg_match_all('/"([^"]+)"\s*=>\s*/s', $params, $matches)) {
		return;
	}
	$values = $matches[1];
	if (!preg_match_all('/\{([^$}]*)\}/', $mask, $matches)) {
		return;
	}
	foreach ($matches[1] as $key) {
		if (!in_array($key, $values)) {
			echo("Böse: {$file}: {$mask}\n");
		}
	}
}

function checkFile(string $file): void {
	$data = file_get_contents($file);
	if (!preg_match_all('/\$this->logger->[a-z]+\(\s*"([^"]*)",\s*\[(.*?)\]\s*\);/s', $data, $matches)) {
		return;
	}
	for ($i = 0; $i < count($matches[1]); $i++) {
		checkCall($file, $matches[1][$i], $matches[2][$i]);
	}
}

$dir = new RecursiveDirectoryIterator(__DIR__);
$recIter = new RecursiveIteratorIterator($dir);
$filterIter = new RegexIterator($recIter, '/\.php$/');

foreach ($filterIter as $file) {
	checkFile($file->getPathname());
}
