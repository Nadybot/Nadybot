<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Block;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\RAID_MODULE\RaidBlockController;

class CreateRaidBlockTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaidBlockController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->string("player", 15)->index();
			$table->string("blocked_from", 20);
			$table->string("blocked_by", 15);
			$table->text("reason");
			$table->integer("time");
			$table->integer("expiration")->nullable()->index();
		});
	}
}
