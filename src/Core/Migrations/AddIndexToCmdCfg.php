<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\CmdCfg;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_12_07_08_37_07)]
class AddIndexToCmdCfg implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = CmdCfg::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->index(['cmdevent']);
			$table->index(['module', 'status']);
		});
	}
}
