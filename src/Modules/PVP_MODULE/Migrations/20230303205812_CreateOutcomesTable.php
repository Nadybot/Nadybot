<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\NotumWarsController;

class CreateOutcomesTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = NotumWarsController::DB_OUTCOMES;
		$db->schema()->create($table, function (Blueprint $table) {
			$table->unsignedSmallInteger("playfield_id");
			$table->unsignedSmallInteger("site_id");
			$table->unsignedInteger("timestamp");
			$table->string("attacker_faction", 7)->nullable(true);
			$table->string("attacker_org", 40)->nullable(true);
			$table->string("losing_faction", 7);
			$table->string("losing_org", 40)->nullable(true);

			$table->index("playfield_id");
			$table->index("site_id");
			$table->index("timestamp");
			$table->index("attacker_org");
			$table->index("losing_org");
		});
	}
}
