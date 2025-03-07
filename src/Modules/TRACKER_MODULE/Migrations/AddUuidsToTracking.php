<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	Types\SchemaMigration,
};
use Nadybot\Modules\TRACKER_MODULE\Tracking;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2025_03_07_14_19_00)]
class AddUuidsToTracking implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			Tracking::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->bigInteger('uid');
				$table->integer('dt');
				$table->string('event', 6);
			},
			'id',
			'dt',
		);
	}
}
