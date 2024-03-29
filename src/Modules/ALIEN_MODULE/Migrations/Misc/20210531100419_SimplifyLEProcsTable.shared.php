<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE\Migrations\Misc;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class SimplifyLEProcsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "leprocs";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("profession", 20)->index();
			$table->string("name", 30);
			$table->string("research_name", 30);
			$table->unsignedTinyInteger("research_lvl")->index();
			$table->unsignedTinyInteger("proc_type")->index();
			$table->unsignedTinyInteger("chance");
			$table->string("modifiers", 255);
			$table->string("duration", 5);
			$table->string("proc_trigger", 10);
			$table->string("description", 255);
		});
	}
}
