<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\TOWER_MODULE\TowerController;

class CreateTowerVictoryTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TowerController::DB_TOWER_VICTORY;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->integer("time")->nullable();
			$table->string("win_guild_name", 50)->nullable();
			$table->string("win_faction", 10)->nullable();
			$table->string("lose_guild_name", 50)->nullable();
			$table->string("lose_faction", 10)->nullable();
			$table->integer("attack_id")->nullable();
		});
	}
}
