<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\LOOT_MODULE\LootHistory;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_12_38_00)]
class MigrateLootHistoryTableToUuid implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			LootHistory::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->unsignedInteger('dt')->index();
				$table->unsignedInteger('roll')->index();
				$table->unsignedInteger('pos');
				$table->unsignedInteger('amount');
				$table->string('name', 200);
				$table->unsignedInteger('icon')->nullable();
				$table->string('added_by', 12)->index();
				$table->string('rolled_by', 12);
				$table->string('display', 200);
				$table->string('comment', 200);
				$table->string('winner', 12)->nullable(true)->index();
			},
			'id',
			'dt'
		);
	}
}
