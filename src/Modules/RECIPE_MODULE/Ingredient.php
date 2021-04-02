<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

class Ingredient extends DBRow {
	/** Internal ID of the ingredient */
	public int $id;

	/** Name of the ingredient. Usually the same as in the AODB */
	public string $name;

	/** Anarchy Online Item ID of this ingredient */
	public ?int $aoid;

	/** Description where you can get this ingredient */
	public ?string $where;

	/**
	 * How many are needed of this ingredient?
	 * @db:ignore
	 */
	public int $amount = 1;

	/**
	 * The pointer to the AO item
	 * @db:ignore
	 */
	public ?AODBEntry $item = null;

	/**
	 * Which QL is needed
	 * @db:ignore
	 */
	public ?int $ql = null;

	/**
	 * Set to true if a higher QL is also okay
	 * @db:ignore
	 */
	public bool $qlCanBeHigher = false;
}
