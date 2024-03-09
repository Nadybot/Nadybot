<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\TRACKER_MODULE\TrackerController;
use Psr\Log\LoggerInterface;

class IndexTrackingTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TrackerController::DB_TRACKING;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->bigInteger("uid")->index()->change();
		});
	}
}
