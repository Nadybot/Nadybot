<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, EventManager, LoggerWrapper, SchemaMigration};

class AllowLongerEventDescription implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = EventManager::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->string("description", 255)->nullable(false)->change();
		});
	}
}
