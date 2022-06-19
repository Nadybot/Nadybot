<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateTrickleTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "trickle";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("id")->primary();
			$table->integer("skill_id");
			$table->string("groupName", 20);
			$table->string("name", 30);
			$table->decimal("amountAgi", 3, 1);
			$table->decimal("amountInt", 3, 1);
			$table->decimal("amountPsy", 3, 1);
			$table->decimal("amountSta", 3, 1);
			$table->decimal("amountStr", 3, 1);
			$table->decimal("amountSen", 3, 1);
		});
	}
}
