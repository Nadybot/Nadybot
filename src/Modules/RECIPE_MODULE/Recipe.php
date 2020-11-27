<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Nadybot\Core\DBRow;

class Recipe extends DBRow {
	public int $id;
	public string $name;
	public string $author;
	public string $recipe;
	/** Last modification of the recipe */
	public int $date;
}
