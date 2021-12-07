<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Pocketboss;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreatePocketbossTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "pocketboss";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table) {
			$table->integer("id")->primary();
			$table->string("pb", 30)->index();
			$table->string("pb_location", 30);
			$table->string("bp_mob", 100);
			$table->smallInteger("bp_lvl");
			$table->string("bp_location", 50);
			$table->string("type", 15)->index();
			$table->string("slot", 15)->index();
			$table->string("line", 15)->index();
			$table->smallInteger("ql");
			$table->integer("itemid");
		});
	}
}
