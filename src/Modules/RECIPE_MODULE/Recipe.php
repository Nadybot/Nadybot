<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Nadybot\Core\DBRow;

class Recipe extends DBRow {
	/** @param int $date Last modification of the recipe */
	public function __construct(
		public int $id,
		public string $name,
		public string $author,
		public string $recipe,
		public int $date,
	) {
	}
}
