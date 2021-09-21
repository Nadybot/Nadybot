<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\TOWER_MODULE\TowerController;

class CreateHotSites implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TowerController::DB_HOT;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->unsignedInteger("playfield_id");
			$table->unsignedSmallInteger("site_number");
			$table->unsignedSmallInteger("ql");
			$table->string("org_name", 255);
			$table->unsignedBigInteger("org_id")->index();
			$table->string("faction", 10)->index();
			$table->unsignedBigInteger("close_time")->index();
			$table->unsignedBigInteger("close_time_override")->index();
			$table->unsignedBigInteger("created_at")->index();
		});
	}
}
