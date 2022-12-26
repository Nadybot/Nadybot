<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration, SettingManager};

class IncreaseIntOptionsSize implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = SettingManager::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->text("intoptions")->nullable(true)->change();
		});
	}
}
