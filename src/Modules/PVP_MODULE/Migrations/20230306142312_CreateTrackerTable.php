<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\SiteTrackerController;
use Psr\Log\LoggerInterface;

class CreateTrackerTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = SiteTrackerController::DB_TRACKER;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->id();
			$table->string("created_by", 12);
			$table->unsignedInteger("created_on");
			$table->string("expression");
			$table->string("events");
		});
	}
}
