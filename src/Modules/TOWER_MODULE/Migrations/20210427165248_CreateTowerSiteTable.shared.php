<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateTowerSiteTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "tower_site";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function(Blueprint $table) {
			$table->integer("playfield_id");
			$table->smallInteger("site_number");
			$table->smallInteger("min_ql");
			$table->smallInteger("max_ql");
			$table->integer("x_coord");
			$table->integer("y_coord");
			$table->string("site_name", 50);
			$table->primary(["playfield_id", "site_number"]);
		});
	}
}
