<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\TRACKER_MODULE\TrackerController;

class CreateOrgTrackingTables implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TrackerController::DB_ORG;
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->unsignedBigInteger("org_id")->primary();
			$table->integer("added_dt");
			$table->string("added_by", 15);
		});

		$table = TrackerController::DB_ORG_MEMBER;
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->unsignedBigInteger("org_id")->index();
			$table->unsignedBigInteger("uid")->index();
			$table->string("name", 12);
			$table->boolean("hidden")->default(false);
		});
	}
}
