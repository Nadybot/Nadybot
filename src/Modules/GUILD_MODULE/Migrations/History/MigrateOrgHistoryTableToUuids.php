<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\History;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\GUILD_MODULE\OrgHistory;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_11_41_00, shared: true)]
class MigrateOrgHistoryTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			OrgHistory::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->text('actor')->nullable();
				$table->text('actee')->nullable();
				$table->text('action')->nullable();
				$table->text('organization')->nullable();
				$table->integer('time')->nullable();
			},
			'id',
			'time'
		);
	}
}
