<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE\Migrations\Designer;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateSymbiantTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "Symbiant";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table) {
			$table->integer("ID")->primary();
			$table->string("Name", 100);
			$table->integer("QL");
			$table->integer("SlotID");
			$table->integer("TreatmentReq");
			$table->integer("LevelReq");
			$table->string("Unit", 20);
		});
	}
}
