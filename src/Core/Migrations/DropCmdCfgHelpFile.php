<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\CmdCfg;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_02_04_13_50_04)]
class DropCmdCfgHelpFile implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = CmdCfg::getTable();
		$db->schema()->dropColumns($table, 'help');
	}
}
