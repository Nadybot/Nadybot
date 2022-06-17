<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Throwable;

class CreatePlayersTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "players";
		if ($db->schema()->hasTable($table)) {
			// Delete entries with duplicate entries
			$db->table($table)
				->groupBy("name", "dimension")
				->havingRaw("COUNT(*) > 1")
				->delete();
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->string("prof_title", 40)->default('')->change();
			});
			try {
				$db->schema()->table($table, function (Blueprint $table): void {
					$table->dropUnique("name");
				});
			} catch (Throwable $e) {
				// Ignore
			}

			$db->schema()->table($table, function (Blueprint $table): void {
				$table->unique(["name", "dimension"]);
			});
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->smallInteger("dimension")->nullable(false)->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("charid")->index();
			$table->string("firstname", 30)->default('');
			$table->string("name", 20)->index();
			$table->string("lastname", 30)->default('');
			$table->smallInteger("level")->nullable();
			$table->string("breed", 20)->default('');
			$table->string("gender", 20)->default('');
			$table->string("faction", 20)->default('');
			$table->string("profession", 20)->default('');
			$table->string("prof_title", 40)->nullable()->default('');
			$table->string("ai_rank", 20)->default('');
			$table->smallInteger("ai_level")->nullable();
			$table->integer("guild_id")->nullable();
			$table->string("guild", 255)->default('');
			$table->string("guild_rank", 20)->default('');
			$table->smallInteger("guild_rank_id")->nullable();
			$table->smallInteger("dimension");
			$table->integer("head_id")->nullable();
			$table->smallInteger("pvp_rating")->nullable();
			$table->string("pvp_title", 20)->nullable();
			$table->string("source", 50)->default('');
			$table->integer("last_update")->nullable();
			$table->unique(["name", "dimension"]);
		});
	}
}
