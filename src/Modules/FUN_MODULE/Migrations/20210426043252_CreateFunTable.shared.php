<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateFunTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "fun";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("type", 20);
			$table->text("content");
		});
	}
}
