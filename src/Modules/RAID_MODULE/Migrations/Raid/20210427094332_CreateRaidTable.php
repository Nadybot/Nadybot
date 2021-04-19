<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\RAID_MODULE\RaidController;

class CreateRaidTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaidController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function(Blueprint $table) {
				$table->id("raid_id")->change();
			});
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id("raid_id");
			$table->string("description", 255)->nullable();
			$table->integer("seconds_per_point");
			$table->integer("announce_interval");
			$table->boolean("locked")->default(false);
			$table->integer("started")->index();
			$table->string("started_by", 20);
			$table->integer("stopped")->nullable()->index();
			$table->string("stopped_by", 20)->nullable();
		});
	}
}
