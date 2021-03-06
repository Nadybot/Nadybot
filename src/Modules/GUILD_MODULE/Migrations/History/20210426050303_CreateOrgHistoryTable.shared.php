<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE\Migrations\History;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateOrgHistoryTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "org_history";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table) {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->text("actor")->nullable();
			$table->text("actee")->nullable();
			$table->text("action")->nullable();
			$table->text("organization")->nullable();
			$table->integer("time")->nullable();
		});
	}
}
