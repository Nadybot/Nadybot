<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\USAGE\UsageController;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_10_19_01_01_08)]
class IndexUsageTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = UsageController::DB_TABLE;
		$db->schema()->table($table, static function (Blueprint $table): void {
			$table->string('type', 10)->change();
			$table->string('command', 20)->index()->change();
			$table->string('sender', 20)->index()->change();
			$table->integer('dt')->index()->change();
		});
	}
}
