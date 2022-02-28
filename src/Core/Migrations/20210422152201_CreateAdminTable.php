<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\AdminManager;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class CreateAdminTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = AdminManager::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table): void {
			$table->string("name", 25)->primary();
			$table->integer("adminlevel")->nullable();
		});
	}
}
