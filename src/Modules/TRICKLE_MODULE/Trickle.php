<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE;

use Nadybot\Core\Attributes\DB\{ColName, Ignore, PK, Shared, Table};
use Nadybot\Core\DBTable;
use Nadybot\Core\Types\{Ability, Skill};

#[Table(name: 'trickle', shared: Shared::Yes)]
class Trickle extends DBTable {
	public function __construct(
		#[PK] public readonly int $id,
		#[ColName('skill_id')] public readonly Skill $skill,
		public readonly string $groupName,
		public readonly float $amountAgi,
		public readonly float $amountInt,
		public readonly float $amountPsy,
		public readonly float $amountSta,
		public readonly float $amountStr,
		public readonly float $amountSen,
		#[Ignore] public ?float $amount=null,
	) {
	}

	public function get(Ability $ability): float {
		return match ($ability) {
			Ability::Agility => $this->amountAgi,
			Ability::Intelligence => $this->amountInt,
			Ability::Psychic => $this->amountPsy,
			Ability::Stamina => $this->amountSta,
			Ability::Strength => $this->amountStr,
			Ability::Sense => $this->amountSen,
		};
	}
}
