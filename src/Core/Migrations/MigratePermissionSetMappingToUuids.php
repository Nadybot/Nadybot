<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{CmdPermSetMapping};
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_10_44_17)]
class MigratePermissionSetMappingToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			CmdPermSetMapping::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('permission_set', 50);
				$table->string('source', 100)->unique();
				$table->string('symbol', 1)->default('!');
				$table->boolean('symbol_optional')->default(false);
				$table->boolean('feedback')->default(true);
			},
			'id',
		);
	}
}
