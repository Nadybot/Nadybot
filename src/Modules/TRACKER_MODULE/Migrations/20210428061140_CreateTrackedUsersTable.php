<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\TRACKER_MODULE\TrackerController;

class CreateTrackedUsersTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TrackerController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->bigInteger("uid")->primary();
			$table->string("name", 25);
			$table->string("added_by", 25);
			$table->integer("added_dt");
		});
	}
}
