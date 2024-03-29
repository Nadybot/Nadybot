<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{CommandManager, DB, LoggerWrapper, SchemaMigration};

class SanitizeCmdCfg implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = CommandManager::DB_TABLE;
		$db->schema()->table($table, function (Blueprint $table): void {
			$table->string("module", 50)->nullable(false)->change();
			$table->string("cmdevent", 6)->nullable(false)->change();
			$table->string("type", 18)->nullable(false)->change();
			$table->text("file")->nullable(false)->change();
			$table->string("cmd", 50)->nullable(false)->change();
			$table->string("admin", 30)->nullable(false)->change();
			$table->string("description", 75)->nullable(false)->change();
			$table->integer("verify")->nullable(false)->change();
			$table->integer("status")->nullable(false)->change();
			$table->string("dependson", 25)->nullable(false)->change();
		});
	}
}
