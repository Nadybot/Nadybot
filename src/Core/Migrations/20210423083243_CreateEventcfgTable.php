<?php declare(strict_types=1);

namespace Nadybot\Core\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, EventManager, SchemaMigration};
use Psr\Log\LoggerInterface;

class CreateEventcfgTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = EventManager::DB_TABLE;
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->string("type", 50)->nullable()->change();
				$table->string("file", 100)->nullable()->change();
				$table->integer("verify")->nullable()->default(0)->index()->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("module", 50)->nullable()->index();
			$table->string("type", 50)->nullable()->index();
			$table->string("file", 100)->nullable()->index();
			$table->string("description", 75)->nullable()->default('none');
			$table->integer("verify")->nullable()->default(0)->index();
			$table->integer("status")->nullable()->default(0);
			$table->string("help", 255)->nullable();
		});
	}
}
