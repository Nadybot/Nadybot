<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Points;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\RAID_MODULE\RaidPointsController;

class MakeRewardIdAutoIncrement implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaidPointsController::DB_TABLE_REWARD;
		$db->schema()->table($table, function(Blueprint $table) {
			$table->id("id")->change();
		});
	}
}
