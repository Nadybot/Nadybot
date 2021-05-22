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
			$table->string("pb", 30)->nullable();
			$table->string("pb_location", 50)->nullable();
			$table->string("bp_mob", 100)->nullable();
			$table->smallInteger("bp_lvl")->nullable();
			$table->string("bp_location", 50)->nullable();
			$table->string("type", 25)->nullable();
			$table->string("slot", 25)->nullable();
			$table->string("line", 25)->nullable();
			$table->smallInteger("ql")->nullable();
			$table->integer("itemid")->nullable();
		});
	}
}
