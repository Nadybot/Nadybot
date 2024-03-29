<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\WORLDBOSS_MODULE\WorldBossController;

class MakeTimerOptional implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = WorldBossController::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->unsignedInteger("timer")->nullable(true)->change();
		});
	}
}
