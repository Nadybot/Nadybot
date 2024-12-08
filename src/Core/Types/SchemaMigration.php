<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Nadybot\Core\DB;
use Psr\Log\LoggerInterface;

interface SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void;
}
