<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\LIMITS\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateRateignorelist implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "rateignorelist";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 20);
			$table->string("added_by", 20);
			$table->integer("added_dt");
		});
	}
}
