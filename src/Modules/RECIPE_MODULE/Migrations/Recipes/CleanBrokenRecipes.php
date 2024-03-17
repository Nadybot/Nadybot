<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE\Migrations\Recipes;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\MigrationOrder(20220709060548)]
class CleanBrokenRecipes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "recipes";
		$db->table($table)
			->where('recipe', 'NOT LIKE', "%\n%")
			->delete();
	}
}
