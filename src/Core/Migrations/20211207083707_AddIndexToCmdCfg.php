<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\CommandManager;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class AddIndexToCmdCfg implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = CommandManager::DB_TABLE;
		$db->schema()->table($table, function(Blueprint $table) {
			$table->index(["cmdevent"]);
			$table->index(["module", "status"]);
		});
	}
}
