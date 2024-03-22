<?php declare(strict_types=1);

namespace Nadybot\Core;

use Stringable;

class DBRow implements Stringable {
	use StringableTrait;

	public function __get(string $value): mixed {
		$backtrace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2);
		$trace = $backtrace[1];
		$trace2 = $backtrace[0];
		$logger = new LoggerWrapper('Core/DB');
		Registry::injectDependencies($logger);
		$logger->warning("Tried to get value '{$value}' from row that doesn't exist: " . var_export($this, true));
		$class = '';
		if (isset($trace['class'])) {
			$class = $trace['class'] . '::';
		}
		$logger->warning('Called by {class}{function}() in {file} line {line}', [
			'class' => $class,
			'function' => $trace['function'],
			'file' => $trace2['file'] ?? 'unknown',
			'line' => $trace2['line'] ?? 'unknown',
		]);
		return null;
	}
}
