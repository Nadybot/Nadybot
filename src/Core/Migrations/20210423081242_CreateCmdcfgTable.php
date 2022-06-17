<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{CommandManager, DB, LoggerWrapper, SchemaMigration};

class CreateCmdcfgTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = CommandManager::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->string("admin", 30)->nullable()->change();
				$table->unique(["cmd", "type"]);
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("module", 50)->nullable();
			$table->string("cmdevent", 6)->nullable();
			$table->string("type", 18)->nullable()->index();
			$table->text("file")->nullable();
			$table->string("cmd", 50)->nullable()->index();
			$table->string("admin", 30)->nullable();
			$table->string("description", 75)->nullable()->default("none");
			$table->integer("verify")->nullable()->default(0)->index();
			$table->integer("status")->nullable()->default(0);
			$table->string("dependson", 25)->nullable()->default("none");
			$table->string("help", 255)->nullable();
			$table->unique(["cmd", "type"]);
		});
	}
}
