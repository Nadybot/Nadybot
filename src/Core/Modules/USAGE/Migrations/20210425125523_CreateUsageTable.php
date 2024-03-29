<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Modules\USAGE\UsageController;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateUsageTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = UsageController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("type", 5);
			$table->string("command", 20);
			$table->string("sender", 20);
			$table->integer("dt");
		});
	}
}
