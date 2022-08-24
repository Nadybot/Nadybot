<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE\Migrations\ArulSaba;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\{DB, LoggerWrapper, SchemaMigration};

class CreateIngredientTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "ingredient";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("id")->primary();
			$table->string("name", 50);
			$table->integer("aoid")->nullable()->index();
			$table->text("where")->nullable();
		});
	}
}
