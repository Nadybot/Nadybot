<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Member;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidMemberController;

class CreateRaidMemberTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaidMemberController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("raid_id")->index();
			$table->string("player", 20)->index();
			$table->integer("joined")->nullable();
			$table->integer("left")->nullable();
		});
	}
}
