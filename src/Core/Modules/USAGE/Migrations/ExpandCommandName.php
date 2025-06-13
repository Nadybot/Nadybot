<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Core\DBSchema\Usage;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_11_30_10_34_04)]
class ExpandCommandName implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Usage::getTable();
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('command', 25)->change();
		});
	}
}
