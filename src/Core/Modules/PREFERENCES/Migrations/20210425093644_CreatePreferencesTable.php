<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PREFERENCES\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreatePreferencesTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = Preferences::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("sender", 30);
			$table->string("name", 30);
			$table->string("value", 400);
		});
	}
}
