<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{CommandManager, DB, LoggerWrapper, SchemaMigration};

class AddIndexToCmdCfg implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = CommandManager::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->index(["cmdevent"]);
			$table->index(["module", "status"]);
		});
	}
}
