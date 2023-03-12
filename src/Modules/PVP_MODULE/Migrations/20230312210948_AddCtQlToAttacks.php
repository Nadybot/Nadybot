<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\NotumWarsController;

class AddCtQlToAttacks implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = NotumWarsController::DB_ATTACKS;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->unsignedSmallInteger("ql")->nullable(true);
		});
	}
}
