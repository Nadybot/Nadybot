<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Points;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\RAID_MODULE\RaidPointsController;
use stdClass;

class CreateRaidPointsLogTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaidPointsController::DB_TABLE_LOG;
		if ($db->schema()->hasTable($table)) {
			if ($db->schema()->hasColumn($table, "individual")) {
				return;
			}
			$db->schema()->table($table, function(Blueprint $table): void {
				$table->boolean("individual")->default(true)->index()->change();
			});
			$db->table($table)->asObj()->each(function(stdClass $log) use ($db, $table) {
				$db->table($table)
					->where("time", $log->time)
					->where("username", $log->username)
					->where("changed_by", $log->changed_by)
					->where("delta", $log->delta)
					->where("reason", $log->reason)
					->where("ticker", $log->ticker)
					->update([
						"individual" => !$log->ticker && !in_array($log->reason, ["reward", "penalty"])
					]);
			});
			return;
		}
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->string("username", 20)->index();
			$table->integer("delta");
			$table->integer("time")->index();
			$table->string("changed_by", 20)->index();
			$table->boolean("individual")->default(true)->index();
			$table->text("reason");
			$table->boolean("ticker")->default(false)->index();
			$table->integer("raid_id")->nullable()->index();
		});
	}
}
