<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateWhompahCitiesRelTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "whompah_cities_rel";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table) {
			$table->integer("city1_id");
			$table->integer("city2_id");
		});
	}
}
