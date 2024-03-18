<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{CommandManager, DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20211207083707)]
class AddIndexToCmdCfg implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = CommandManager::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->index(["cmdevent"]);
			$table->index(["module", "status"]);
		});
	}
}
