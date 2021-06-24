<?php declare(strict_types=1);

namespace Nadybot\Core;

interface SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void;
}
