<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, SchemaMigration, SettingManager};
use Psr\Log\LoggerInterface;

class IncreaseIntOptionsSize implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = SettingManager::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table) {
			$table->text("intoptions")->nullable(true)->change();
		});
	}
}
