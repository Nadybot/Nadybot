<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateScoutInfoTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "scout_info";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->integer("playfield_id");
			$table->smallInteger("site_number");
			$table->integer("scouted_on");
			$table->string("scouted_by", 20);
			$table->smallInteger("ct_ql");
			$table->string("guild_name", 50);
			$table->string("faction", 7)->default('');
			$table->integer("close_time");
			$table->boolean("is_current")->default(true);
			$table->primary(["playfield_id", "site_number"]);
		});
	}
}
