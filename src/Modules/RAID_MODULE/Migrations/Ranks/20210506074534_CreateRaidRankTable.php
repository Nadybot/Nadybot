<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE\Migrations\Ranks;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\RAID_MODULE\RaidRankController;

class CreateRaidRankTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaidRankController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 25)->primary();
			$table->integer("rank");
		});
	}
}
