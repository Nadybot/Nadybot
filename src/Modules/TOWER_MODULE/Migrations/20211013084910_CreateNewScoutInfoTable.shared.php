<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateNewScoutInfoTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "scout_info";
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->renameColumn("ct_ql", "ql");
		});
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->renameColumn("guild_name", "org_name");
		});
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->dropColumn("is_current");
		});
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->string("scouted_by", 15)->change();
			$table->smallInteger("ql")->nullable(true)->change();
			$table->string("org_name", 100)->nullable(true)->index()->change();
			$table->string("faction", 7)->nullable(true)->index()->change();
			$table->integer("close_time")->nullable(true)->index()->change();
			$table->unsignedInteger("created_at")->nullable(true);
			$table->unsignedInteger("penalty_duration")->nullable(true);
			$table->unsignedInteger("penalty_until")->nullable(true);
			$table->string("source", 20)->default('scout');
		});
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->string("faction", 7)->default(null)->change();
		});
	}
}
