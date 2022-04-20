<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Points;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\RAID_MODULE\RaidPointsController;

class CreateRaidRewardTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaidPointsController::DB_TABLE_REWARD;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function(Blueprint $table): void {
				$table->integer("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->integer("id")->primary();
			$table->string("name", 20)->index();
			$table->integer("points");
			$table->string("reason", 100);
		});
	}
}
