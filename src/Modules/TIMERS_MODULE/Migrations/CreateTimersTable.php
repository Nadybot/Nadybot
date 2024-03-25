<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\TIMERS_MODULE\TimerController;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_27_16_36_41)]
class CreateTimersTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = TimerController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('name', 255)->nullable();
			$table->string('owner', 25)->nullable();
			$table->string('mode', 50)->nullable();
			$table->integer('endtime')->nullable();
			$table->integer('settime')->nullable();
			$table->string('callback', 255)->nullable();
			$table->string('data', 255)->nullable();
			$table->text('alerts')->nullable();
		});
	}
}
