<?php declare(strict_types=1);

namespace Nadybot\Modules\EVENTS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\EVENTS_MODULE\EventModel;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_01_21_24_00, shared: true)]
class MigrateCreateEventsTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			EventModel::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->integer('time_submitted');
				$table->string('submitter_name', 25);
				$table->string('event_name', 255);
				$table->integer('event_date')->nullable();
				$table->text('event_desc')->nullable();
				$table->text('event_attendees')->nullable();
			},
			'id',
			'time_submitted',
		);
	}
}
