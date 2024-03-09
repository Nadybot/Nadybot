<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidController;
use Psr\Log\LoggerInterface;

class CreateRaidLogTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaidController::DB_TABLE_LOG;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("raid_id")->index();
			$table->string("description", 255)->nullable();
			$table->integer("seconds_per_point");
			$table->integer("announce_interval");
			$table->boolean("locked");
			$table->integer("time")->index();
		});
	}
}
