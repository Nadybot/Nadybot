<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Audit;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_09_07_01)]
class MigrateAuditToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Audit::getTable();
		$db->migrateIdToUuid($table, 'id', 'time');
	}
}
