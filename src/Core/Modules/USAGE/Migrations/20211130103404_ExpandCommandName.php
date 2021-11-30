<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Modules\USAGE\UsageController;
use Nadybot\Core\SchemaMigration;

class ExpandCommandName implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = UsageController::DB_TABLE;
		$db->schema()->table($table, function(Blueprint $table) {
			$table->string("command", 25)->change();
		});
	}
}
