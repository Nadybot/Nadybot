<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{ColName, MapRead};
use Nadybot\Core\Types\{Ability, ImplantSlot};
use Nadybot\Core\{DBRow, Types\Profession};

class PremadeSearchResult extends DBRow {
	public function __construct(
		#[MapRead([ImplantSlot::class, 'fromTypeID'])] public ImplantSlot $slot,
		#[
			MapRead([Profession::class, 'fromNumber']),
			ColName('profession_id')
		] public Profession $profession,
		#[
			MapRead([Ability::class, 'fromID']),
			ColName('ability_id')
		] public Ability $ability,
		public string $shiny,
		public string $bright,
		public string $faded,
	) {
	}
}
