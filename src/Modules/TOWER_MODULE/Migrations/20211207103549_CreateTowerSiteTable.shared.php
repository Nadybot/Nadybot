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
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->integer("playfield_id")->index();
			$table->smallInteger("site_number")->index();
			$table->smallInteger("min_ql");
			$table->smallInteger("max_ql");
			$table->integer("x_coord");
			$table->integer("y_coord");
			$table->string("site_name", 32);
			$table->unsignedSmallInteger("timing")->default(0);
			$table->unsignedSmallInteger("enabled")->default(1)->index();
			$table->primary(["playfield_id", "site_number"]);
			$table->index(["playfield_id", "enabled"]);
		});
	}
}
