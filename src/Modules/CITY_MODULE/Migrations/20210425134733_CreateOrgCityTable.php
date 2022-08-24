<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\CITY_MODULE\CloakController;

class CreateOrgCityTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = CloakController::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("time")->nullable();
			$table->string("action", 10)->nullable();
			$table->string("player", 25)->nullable();
		});
	}
}
