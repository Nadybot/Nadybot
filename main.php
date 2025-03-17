<?php declare(strict_types=1);

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/*
xdebug_start_code_coverage(XDEBUG_CC_UNUSED);
xdebug_set_filter(
	XDEBUG_FILTER_CODE_COVERAGE,
	defined("XDEBUG_PATH_WHITELIST") ? XDEBUG_PATH_WHITELIST : XDEBUG_PATH_INCLUDE,
	[ __DIR__ . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR ]
);
*/
if (!@file_exists(__DIR__ . '/vendor/autoload.php')) { // @phpstan-ignore-line
	fwrite( // @phpstan-ignore-line
		\STDERR,
		"Nadybot cannot find the composer modules in 'vendor'.\n".
		"Please run 'composer install' to install all missing modules\n".
		"or download one of the Nadybot bundles and copy the 'vendor'\n".
		"directory from the zip-file into the Nadybot main directory.\n".
		"\n".
		"See https://github.com/Nadybot/Nadybot/wiki/Running#cloning-the-repository\n".
		"for more information.\n"
	);
	sleep(5);
	exit(1);
}

require 'vendor/autoload.php';

/** @return list<TValue> */
$toList = function (): array {
	return array_values($this->map(static fn ($value) => $value instanceof Arrayable ? array_values($value->toArray()) : $value)->all());
};
Collection::macro('toList', $toList);

/** @return TValue */
$lastOrFail = function (): mixed {
	$result = $this->last();
	if (!isset($result)) {
		throw new \Illuminate\Support\ItemNotFoundException();
	}
	return $result;
};
Collection::macro('lastOrFail', $lastOrFail);

/**
 * Get the int values of a given key.
 *
 * @param string|int|array<array-key, string> $value
 * @param null|string                         $key
 *
 * @return static<array-key, int>
 */
$pluckInts = function ($value, $key=null): mixed {
	return $this->pluck($value, $key);
};
Collection::macro('pluckInts', $pluckInts);

/**
 * Get the string values of a given key.
 *
 * @param string|int|array<array-key, string> $value
 * @param null|string                         $key
 *
 * @return static<array-key, string>
 */
$pluckStrings = function ($value, $key=null): mixed {
	return $this->pluck($value, $key);
};
Collection::macro('pluckStrings', $pluckStrings);

/**
 * Get the object values of a given key.
 *
 * @template T of object
 *
 * @param string|int|array<array-key, string> $value
 * @param null|string                         $key
 * @param class-string<T>                     $class
 *
 * @return static<array-key, T>
 */
$pluckObjects = function (string $className, string|int|array $value, ?string $key=null): mixed {
	return $this->pluck($value, $key);
};
Collection::macro('pluckObjects', $pluckObjects);

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
