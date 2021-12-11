<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\TRACKER_MODULE\TrackerController;

class IndexTrackingTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = TrackerController::DB_TRACKING;
		$db->schema()->table($table, function(Blueprint $table) {
			$table->bigInteger("uid")->index()->change();
		});
	}
}
