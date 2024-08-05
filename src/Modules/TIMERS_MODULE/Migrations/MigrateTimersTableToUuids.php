<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\TIMERS_MODULE\{Timer};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_08_05_14_03_00)]
class MigrateTimersTableToUuids implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->migrateIdToUuid(
			Timer::getTable(),
			static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('name', 255);
				$table->string('owner', 25);
				$table->string('mode', 50)->nullable(true);
				$table->integer('endtime')->nullable(true);
				$table->integer('settime');
				$table->string('callback', 255);
				$table->string('data', 255)->nullable(true);
				$table->text('alerts');
				$table->string('origin', 100)->nullable(true);
			},
			'id',
			'settime',
		);
	}
}
