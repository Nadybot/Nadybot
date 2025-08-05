<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

/** A single step in a recipe */
class RecipeStep {
	/**
	 * @param string      $source The source item to use
	 * @param string      $target The item to use `$source` on
	 * @param string      $result The resulting item
	 * @param null|string $skills The skills required for this step. `null` if none required
	 */
	public function __construct(
		public string $source,
		public string $target,
		public string $result,
		public ?string $skills=null,
	) {
	}
}
