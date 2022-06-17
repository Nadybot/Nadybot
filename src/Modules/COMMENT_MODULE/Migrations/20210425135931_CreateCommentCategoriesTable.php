<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateCommentCategoriesTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "comment_categories_<myname>";
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->string("name", 20)->primary();
			$table->string("created_by", 15);
			$table->integer("created_at");
			$table->string("min_al_read", 25)->default('all');
			$table->string("min_al_write", 25)->default('all');
			$table->boolean("user_managed")->nullable()->default(true);
		});
	}
}
