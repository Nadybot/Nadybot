<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\TRACKER_MODULE\TrackerController;
use Psr\Log\LoggerInterface;

class CreateTrackingTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TrackerController::DB_TRACKING;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->bigInteger("uid");
			$table->integer("dt");
			$table->string("event", 6);
		});
	}
}
