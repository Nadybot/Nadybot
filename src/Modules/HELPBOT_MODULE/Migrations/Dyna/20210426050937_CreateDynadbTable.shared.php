<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE\Migrations\Dyna;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateDynadbTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "dynadb";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table) {
			$table->integer("playfield_id");
			$table->string("mob", 200)->nullable();
			$table->integer("minQl")->nullable();
			$table->integer("maxQl")->nullable();
			$table->integer("cX")->nullable();
			$table->integer("cY")->nullable();
		});
	}
}
