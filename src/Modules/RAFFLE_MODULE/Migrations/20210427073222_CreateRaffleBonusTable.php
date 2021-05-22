<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Modules\RAFFLE_MODULE\RaffleController;

class CreateRaffleBonusTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = RaffleController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->string("name", 20)->primary();
			$table->integer("bonus")->default(0);
		});
	}
}
