<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

/** An item used in a recipe */
class RecipeItem {
	/**
	 * @param string $alias   How to reference this item
	 * @param int    $item_id The AOID
	 * @param int    $ql      The QL of this item
	 */
	public function __construct(
		public string $alias,
		public int $item_id,
		public int $ql,
	) {
	}
}
