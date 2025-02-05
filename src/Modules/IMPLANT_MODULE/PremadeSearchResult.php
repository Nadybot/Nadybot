<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{ColName, MapRead};
use Nadybot\Core\Types\Ability;
use Nadybot\Core\{DBRow, Types\Profession};

class PremadeSearchResult extends DBRow {
	public function __construct(
		public string $slot,
		#[
			MapRead([Profession::class, 'byNumber']),
			ColName('profession_id')
		] public Profession $profession,
		#[
			MapRead([Ability::class, 'byID']),
			ColName('ability_id')
		] public Ability $ability,
		public string $shiny,
		public string $bright,
		public string $faded,
	) {
	}
}
