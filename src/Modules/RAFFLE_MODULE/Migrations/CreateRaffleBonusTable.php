<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\RAFFLE_MODULE\RaffleController;
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20210427073222)]
class CreateRaffleBonusTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = RaffleController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 20)->primary();
			$table->integer("bonus")->default(0);
		});
	}
}
