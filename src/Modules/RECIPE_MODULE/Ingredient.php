<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};
use Nadybot\Modules\ITEMS_MODULE\AODBItem;

class Ingredient extends DBRow {
	/** Internal ID of the ingredient */
	public int $id;

	/** Name of the ingredient. Usually the same as in the AODB */
	public string $name;

	/** Anarchy Online Item ID of this ingredient */
	public ?int $aoid;

	/** Description where you can get this ingredient */
	public ?string $where;

	/** How many are needed of this ingredient? */
	#[NCA\DB\Ignore]
	public int $amount = 1;

	/** The pointer to the AO item */
	#[NCA\DB\Ignore]
	public ?AODBItem $item = null;

	/** Which QL is needed */
	#[NCA\DB\Ignore]
	public ?int $ql = null;

	/** Set to true if a higher QL is also okay */
	#[NCA\DB\Ignore]
	public bool $qlCanBeHigher = false;
}
