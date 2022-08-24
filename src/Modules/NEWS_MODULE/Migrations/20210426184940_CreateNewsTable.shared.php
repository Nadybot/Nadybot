<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateNewsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "news";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->integer("time");
			$table->string("name", 30)->nullable();
			$table->text("news")->nullable();
			$table->tinyInteger("sticky");
			$table->tinyInteger("deleted");
		});
	}
}
