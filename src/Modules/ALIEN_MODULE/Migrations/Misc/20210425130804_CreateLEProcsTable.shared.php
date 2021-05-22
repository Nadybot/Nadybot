<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Misc;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateLEProcsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "leprocs";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->string("profession", 20);
			$table->string("name", 50);
			$table->string("research_name", 50)->nullable();
			$table->integer("research_lvl");
			$table->char("proc_type", 6)->nullable();
			$table->string("chance", 20)->nullable();
			$table->string("modifiers", 255);
			$table->string("duration", 20);
			$table->string("proc_trigger", 20);
			$table->string("description", 255);
		});
	}
}
