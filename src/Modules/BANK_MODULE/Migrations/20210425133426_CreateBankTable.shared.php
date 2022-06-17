<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateBankTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "bank";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 150)->nullable();
			$table->integer("lowid")->nullable();
			$table->integer("highid")->nullable();
			$table->integer("ql")->nullable();
			$table->string("player", 20)->nullable();
			$table->string("container", 150)->nullable();
			$table->integer("container_id")->nullable();
			$table->string("location", 150)->nullable();
		});
	}
}
