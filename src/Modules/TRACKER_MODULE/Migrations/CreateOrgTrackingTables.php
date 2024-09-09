<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\TRACKER_MODULE\{TrackingOrg, TrackingOrgMember};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_10_04_08_48_07)]
class CreateOrgTrackingTables implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TrackingOrg::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->unsignedBigInteger('org_id')->primary();
			$table->integer('added_dt');
			$table->string('added_by', 15);
		});

		$table = TrackingOrgMember::getTable();
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->unsignedBigInteger('org_id')->index();
			$table->unsignedBigInteger('uid')->index();
			$table->string('name', 12);
			$table->boolean('hidden')->default(false);
		});
	}
}
