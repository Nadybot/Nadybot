<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateNanosTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "nanos";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table) {
			$table->unsignedInteger("crystal_id")->nullable()->index();
			$table->unsignedInteger("nano_id")->primary();
			$table->unsignedInteger("ql");
			$table->string("crystal_name", 70)->nullable();
			$table->string("nano_name", 70);
			$table->string("school", 26);
			$table->string("strain", 45);
			$table->integer("strain_id");
			$table->string("sub_strain", 45);
			$table->string("professions", 50);
			$table->string("location", 45);
			$table->integer("nano_cost");
			$table->boolean("froob_friendly")->index();
			$table->integer("sort_order")->index();
		});
	}
}
