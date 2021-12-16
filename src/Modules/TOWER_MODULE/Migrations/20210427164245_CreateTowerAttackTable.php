<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\TOWER_MODULE\TowerController;

class CreateTowerAttackTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TowerController::DB_TOWER_ATTACK;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function(Blueprint $table): void {
				$table->id("id")->change();
				$table->string("att_player", 50)->nullable()->change();
			});
			return;
		}
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->id();
			$table->integer("time")->nullable();
			$table->string("att_guild_name", 50)->nullable();
			$table->string("att_faction", 10)->nullable();
			$table->string("att_player", 50)->nullable();
			$table->integer("att_level")->nullable();
			$table->integer("att_ai_level")->nullable();
			$table->string("att_profession", 15)->nullable();
			$table->string("def_guild_name", 50)->nullable();
			$table->string("def_faction", 10)->nullable();
			$table->integer("playfield_id")->nullable();
			$table->integer("site_number")->nullable();
			$table->integer("x_coords")->nullable();
			$table->integer("y_coords")->nullable();
		});
	}
}
