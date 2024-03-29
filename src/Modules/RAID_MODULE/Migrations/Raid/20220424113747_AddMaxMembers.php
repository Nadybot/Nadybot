<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Raid;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidController;

class AddMaxMembers implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaidController::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->unsignedInteger("max_members")->nullable(true);
		});

		$table = RaidController::DB_TABLE_LOG;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->unsignedInteger("max_members")->nullable(true);
		});
	}
}
