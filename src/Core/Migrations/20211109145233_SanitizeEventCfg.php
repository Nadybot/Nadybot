<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\EventManager;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class SanitizeEventCfg implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = EventManager::DB_TABLE;
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->string("module", 50)->nullable(false)->change();
			$table->string("type", 50)->nullable(false)->change();
			$table->string("file", 100)->nullable(false)->change();
			$table->string("description", 75)->nullable(false)->change();
			$table->integer("verify")->nullable(false)->change();
			$table->integer("status")->nullable(false)->change();
		});
	}
}
