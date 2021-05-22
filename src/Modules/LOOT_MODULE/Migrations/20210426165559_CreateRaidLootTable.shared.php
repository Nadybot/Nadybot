<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateRaidLootTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "raid_loot";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table) {
			$table->integer("id")->primary();
			$table->string("raid", 30)->index();
			$table->string("category", 50)->index();
			$table->integer("ql");
			$table->string("name", 255);
			$table->string("comment", 255);
			$table->integer("multiloot");
			$table->integer("aoid")->nullable();
		});
	}
}
