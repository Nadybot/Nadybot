<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidController;

class AddRaidLogTickerPaused implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaidController::DB_TABLE_LOG;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->boolean("ticker_paused")->default(false);
		});
	}
}
