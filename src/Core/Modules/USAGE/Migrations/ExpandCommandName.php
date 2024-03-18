<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\USAGE\UsageController;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20211130103404)]
class ExpandCommandName implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = UsageController::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->string("command", 25)->change();
		});
	}
}
