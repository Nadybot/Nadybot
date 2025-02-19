<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Nadybot\Core\DB;
use Psr\Log\LoggerInterface;

/** This interface is used to apply changes to the database schema */
interface SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void;
}
