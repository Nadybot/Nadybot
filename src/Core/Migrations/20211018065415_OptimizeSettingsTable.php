<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;

class OptimizeSettingsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = SettingManager::DB_TABLE;
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->text("value")->nullable(true)->change();
			$table->text("options")->nullable(true)->change();
			$table->text("description")->nullable(true)->change();
		});
	}
}
