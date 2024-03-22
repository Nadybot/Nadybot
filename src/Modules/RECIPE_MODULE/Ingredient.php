<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};
use Nadybot\Modules\ITEMS_MODULE\AODBItem;

class Ingredient extends DBRow {
	/**
	 * @param string    $name          Name of the ingredient. Usually the same as in the AODB
	 * @param ?int      $aoid          Anarchy Online Item ID of this ingredient
	 * @param ?string   $where         Description where you can get this ingredient
	 * @param int       $amount        How many are needed of this ingredient?
	 * @param ?AODBItem $item          The pointer to the AO item
	 * @param ?int      $ql            Which QL is needed
	 * @param bool      $qlCanBeHigher Set to true if a higher QL is also okay
	 * @param ?int      $id            Internal ID of the ingredient
	 */
	public function __construct(
		public string $name,
		public ?int $aoid=null,
		public ?string $where=null,
		#[NCA\DB\Ignore] public int $amount=1,
		#[NCA\DB\Ignore] public ?AODBItem $item=null,
		#[NCA\DB\Ignore] public ?int $ql=null,
		#[NCA\DB\Ignore] public bool $qlCanBeHigher=false,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
