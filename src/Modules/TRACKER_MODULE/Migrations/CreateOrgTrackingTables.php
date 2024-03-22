<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\TRACKER_MODULE\TrackerController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_211_004_084_807)]
class CreateOrgTrackingTables implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TrackerController::DB_ORG;
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->unsignedBigInteger('org_id')->primary();
			$table->integer('added_dt');
			$table->string('added_by', 15);
		});

		$table = TrackerController::DB_ORG_MEMBER;
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->unsignedBigInteger('org_id')->index();
			$table->unsignedBigInteger('uid')->index();
			$table->string('name', 12);
			$table->boolean('hidden')->default(false);
		});
	}
}
