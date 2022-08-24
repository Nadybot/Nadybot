<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration, SettingManager};

class CreateSettingsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = SettingManager::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 50)->index();
			$table->string("module", 50)->nullable();
			$table->string("type", 30)->nullable();
			$table->string("mode", 10)->nullable();
			$table->string("value", 255)->nullable()->default('0');
			$table->string("options", 255)->nullable()->default('0');
			$table->string("intoptions", 50)->nullable()->default('0');
			$table->string("description", 75)->nullable();
			$table->string("source", 5)->nullable();
			$table->string("admin", 25)->nullable();
			$table->integer("verify")->nullable()->default(0);
			$table->string("help", 255)->nullable();
		});
	}
}
