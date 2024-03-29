<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, HelpManager, LoggerWrapper, SchemaMigration};

class CreateHlpcfgTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = HelpManager::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 25)->index();
			$table->string("module", 50)->nullable();
			$table->string("file", 255)->nullable();
			$table->string("description", 75)->nullable();
			$table->string("admin", 10)->nullable();
			$table->integer("verify")->nullable()->default(0);
		});
	}
}
