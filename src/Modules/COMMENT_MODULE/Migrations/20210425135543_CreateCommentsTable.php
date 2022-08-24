<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateCommentsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "comments_<myname>";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("character", 15)->index();
			$table->string("created_by", 15);
			$table->integer("created_at");
			$table->string("category", 20)->index();
			$table->text("comment");
		});
	}
}
