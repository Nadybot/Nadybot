<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{CmdPermSetMapping};
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_10_44_17)]
class MigratePermissionSetMappingToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = CmdPermSetMapping::getTable();
		$db->migrateIdToUuid($table, 'id');
	}
}
