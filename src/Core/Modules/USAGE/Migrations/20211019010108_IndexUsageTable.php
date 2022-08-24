<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Modules\USAGE\UsageController;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class IndexUsageTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = UsageController::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->string("type", 10)->change();
			$table->string("command", 20)->index()->change();
			$table->string("sender", 20)->index()->change();
			$table->integer("dt")->index()->change();
		});
	}
}
