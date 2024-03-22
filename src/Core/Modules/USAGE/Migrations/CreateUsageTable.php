<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\USAGE\UsageController;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_425_125_523)]
class CreateUsageTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = UsageController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->string('type', 5);
			$table->string('command', 20);
			$table->string('sender', 20);
			$table->integer('dt');
		});
	}
}
