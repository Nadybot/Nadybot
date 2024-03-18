<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Points;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidPointsController;
use Psr\Log\LoggerInterface;
use stdClass;

#[NCA\Migration(order: 20210427102454)]
class CreateRaidPointsLogTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidPointsController::DB_TABLE_LOG;
		if ($db->schema()->hasTable($table)) {
			if ($db->schema()->hasColumn($table, "individual")) {
				return;
			}
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->boolean("individual")->default(true)->index()->change();
			});
			$db->table($table)->get()->each(function (stdClass $log) use ($db, $table) {
				$db->table($table)
					->where("time", (int)$log->time)
					->where("username", (string)$log->username)
					->where("changed_by", (string)$log->changed_by)
					->where("delta", (int)$log->delta)
					->where("reason", (string)$log->reason)
					->where("ticker", (int)$log->ticker)
					->update([
						"individual" => !$log->ticker && !in_array((string)$log->reason, ["reward", "penalty"]),
					]);
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
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
