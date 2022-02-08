<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\CommandManager;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class DropCmdCfgHelpFile implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = CommandManager::DB_TABLE;
		$db->schema()->dropColumns($table, "help");
	}
}
