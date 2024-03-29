<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Modules\USAGE\UsageController;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class ExpandCommandName implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = UsageController::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->string("command", 25)->change();
		});
	}
}
