<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

/** Represents a recipe stored as JSON */
class RecipeData {
	/**
	 * @param string       $name   Name of the recipe
	 * @param string       $author Name of the recipe's author
	 * @param RecipeItem[] $items  List of items required for this recipe
	 * @param RecipeStep[] $steps  A list of steps involved for this recipe
	 */
	public function __construct(
		public string $name,
		public string $author,
		#[CastListToType(RecipeItem::class)] public array $items,
		#[CastListToType(RecipeStep::class)] public array $steps,
	) {
	}
}
