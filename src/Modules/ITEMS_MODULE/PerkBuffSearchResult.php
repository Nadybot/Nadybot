<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\DBRow;

class PerkBuffSearchResult extends DBRow {
	/** @param array<string,int> $profMax */
	public function __construct(
		public int $amount,
		public int $perk_level,
		public string $profs,
		public string $unit,
		public string $expansion,
		public array $profMax=[],
		public ?string $name=null,
	) {
	}
}
