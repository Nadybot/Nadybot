<?php declare(strict_types=1);

namespace Nadybot\Modules\SPIRITS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateSpiritsdbTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "spiritsdb";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table) {
			$table->unsignedInteger("id")->nullable();
			$table->string("name", 255)->nullable();
			$table->unsignedSmallInteger("ql")->nullable();
			$table->string("spot", 25)->nullable();
			$table->unsignedSmallInteger("level")->nullable();
			$table->unsignedSmallInteger("agility")->nullable();
			$table->unsignedSmallInteger("sense")->nullable();
		});
	}
}
