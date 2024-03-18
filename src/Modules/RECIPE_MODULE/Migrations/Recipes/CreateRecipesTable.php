<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE\Migrations\Recipes;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210427134821)]
class CreateRecipesTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "recipes";
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->integer("id")->primary();
			$table->string("name", 50);
			$table->string("author", 50);
			$table->text("recipe");
			$table->integer("date");
		});
	}
}