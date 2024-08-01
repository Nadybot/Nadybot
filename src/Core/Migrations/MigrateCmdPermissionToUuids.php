<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\CmdPermission;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_15_15_00)]
class MigrateCmdPermissionToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			CmdPermission::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('permission_set', 50)->index();
				$table->string('cmd', 50)->index();
				$table->boolean('enabled')->default(false);
				$table->string('access_level', 30);
				$table->unique(['cmd', 'permission_set']);
			},
			'id'
		);
	}
}
