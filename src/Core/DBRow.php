<?php declare(strict_types=1);

namespace Nadybot\Core;

class DBRow {
	public function __get(string $value) {
		$logger = new LoggerWrapper('DB');
		$logger->log('WARN', "Tried to get value '$value' from row that doesn't exist");
	}
}
