<?php declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use function Safe\{file_get_contents, preg_match_all};

function checkCall(string $call, string $file, int $line, string $mask, string $params): bool {
	if (!preg_match_all('/"([^"]+)"\s*=>\s*/s', $params, $matches)) {
		return false;
	}
	$values = $matches[1];
	if (!preg_match_all('/\{([^$}]*)\}/', $mask, $matches)) {
		return false;
	}
	$found = false;
	foreach ($matches[1] as $key) {
		if (!in_array($key, $values)) {
			$found = true;
			$call = str_replace("{{$key}}", "\e[31m{{$key}}\e[0m", $call);
		}
	}
	if ($found) {
		echo("{$file}:{$line}:\n\t" . join("\n\t", explode("\n", $call)) . "\n");
	}
	return $found;
}

function checkFile(string $file): bool {
	$data = file_get_contents($file);
	if (!preg_match_all(
		'/[ \t]*\$this->logger->[a-z]+\(\s*"([^"]*)",\s*\[(.*?)\]\s*\);/s',
		$data,
		$matches,
		PREG_OFFSET_CAPTURE
	)) {
		return false;
	}
	$found = false;
	for ($i = 0; $i < count($matches[1]); $i++) {
		$line = substr_count($data, "\n", 0, $matches[0][$i][1]) + 1;
		$call = $matches[0][$i][0];
		if (checkCall($call, $file, $line, $matches[1][$i][0], $matches[2][$i][0])) {
			$found = true;
		}
	}
	return $found;
}

$dir = new RecursiveDirectoryIterator(__DIR__);
$recIter = new RecursiveIteratorIterator($dir);
$filterIter = new RegexIterator($recIter, '/\.php$/');

$found = false;
foreach ($filterIter as $file) {
	if (checkFile($file->getPathname())) {
		$found = true;
	}
}

exit($found ? 1 : 0);