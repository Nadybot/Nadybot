<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\{CommandManager, DB, SchemaMigration};
use Psr\Log\LoggerInterface;

class DropCmdCfgHelpFile implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = CommandManager::DB_TABLE;
		$db->schema()->dropColumns($table, "help");
	}
}
