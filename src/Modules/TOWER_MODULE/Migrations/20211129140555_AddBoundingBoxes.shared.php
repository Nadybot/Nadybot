<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class AddBoundingBoxes implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "tower_site_bounds";
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->unsignedInteger("playfield_id")->index();
			$table->unsignedInteger("site_number")->index();
			$table->unsignedInteger("x_coord1")->index();
			$table->unsignedInteger("x_coord2")->index();
			$table->unsignedInteger("y_coord1")->index();
			$table->unsignedInteger("y_coord2")->index();
		});
	}
}
