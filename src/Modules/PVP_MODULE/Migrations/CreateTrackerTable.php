<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\TrackerEntry;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_03_06_14_23_12)]
class CreateTrackerTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TrackerEntry::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->string('created_by', 12);
			$table->unsignedInteger('created_on');
			$table->string('expression');
			$table->string('events');
		});
	}
}
