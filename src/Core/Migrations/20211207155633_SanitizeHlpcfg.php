<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\HelpManager;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class SanitizeHlpcfg implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = HelpManager::DB_TABLE;
		$db->schema()->table($table, function(Blueprint $table): void {
			$table->string("module", 50)->nullable(false)->change();
			$table->string("file", 255)->nullable(false)->change();
			$table->string("description", 75)->nullable(false)->change();
			$table->string("admin", 10)->nullable(false)->change();
			$table->integer("verify")->nullable(false)->change();
		});
	}
}
