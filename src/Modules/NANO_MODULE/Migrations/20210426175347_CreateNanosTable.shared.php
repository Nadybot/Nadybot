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
			$table->integer("crystal_id")->nullable();
			$table->integer("nano_id")->nullable();
			$table->integer("ql")->nullable();
			$table->string("crystal_name", 255)->nullable();
			$table->string("nano_name", 255)->nullable();
			$table->string("school", 255)->nullable();
			$table->string("strain", 255)->nullable();
			$table->integer("strain_id")->nullable();
			$table->string("sub_strain", 255)->nullable();
			$table->string("professions", 255)->nullable();
			$table->string("location", 255)->nullable();
			$table->integer("nano_cost")->nullable();
			$table->boolean("froob_friendly")->nullable();
			$table->integer("sort_order");
		});
	}
}
