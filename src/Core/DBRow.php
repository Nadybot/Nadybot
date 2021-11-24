<?php declare(strict_types=1);

namespace Nadybot\Core;

class DBRow {
	public function __get(string $value) {
		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
		$trace = $backtrace[1];
		$trace2 = $backtrace[0];
		$logger = new LoggerWrapper('DB');
		$logger->log('WARN', "Tried to get value '$value' from row that doesn't exist: " . var_export($this, true));
		$class = "";
		if (isset($trace['class'])) {
			$class = $trace['class'] . "::";
		}
		$logger->log('WARN', "Called by {$class}{$trace['function']}() in {$trace2['file']} line {$trace2['line']}");
	}
}
