<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\AccessManager;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateAuditTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = AccessManager::DB_TABLE;
		$db->schema()->create($table, function(Blueprint $table) {
			$table->id();
			$table->string("actor", 12);
			$table->string("actee", 12)->nullable();
			$table->string("action", 50);
			$table->string("value", 50)->nullable();
			$table->integer("time");
		});
	}
}
