<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\RankMapping;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\GUILD_MODULE\GuildRankController;

class CreateOrgRankMappingTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = GuildRankController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->string("access_level", 15)->primary();
			$table->integer("min_rank")->unique();
		});
	}
}
