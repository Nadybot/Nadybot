<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\TRACKER_MODULE\TrackerController;

class AddHiddenColumnToTrackedUsers implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TrackerController::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->boolean("hidden")->default(false);
		});
	}
}
