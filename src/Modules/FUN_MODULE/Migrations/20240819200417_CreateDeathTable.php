<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\FUN_MODULE\DeathController;

class CreateDeathTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = DeathController::DB_TABLE;
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("character", 12)->primary();
			$table->integer("counter")->default(0);
		});
	}
}
