<?php declare(strict_types=1);

namespace Nadybot\Core;

interface SchemaMigration {
	/**
	 * @psalm-suppress MissingReturnType
	 * @phpstan-ignore-next-line
	 */
	public function migrate(LoggerWrapper $logger, DB $db);
}
