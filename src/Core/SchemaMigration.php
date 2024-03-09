<?php declare(strict_types=1);

namespace Nadybot\Core;

use Psr\Log\LoggerInterface;

interface SchemaMigration {
	/**
	 * @psalm-suppress MissingReturnType
	 *
	 * @phpstan-ignore-next-line
	 */
	public function migrate(LoggerInterface $logger, DB $db);
}
