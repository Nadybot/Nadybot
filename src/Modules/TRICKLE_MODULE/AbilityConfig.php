<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE;

use Nadybot\Core\Types\Ability;

class AbilityConfig {
	public int $agi = 0;
	public int $int = 0;
	public int $psy = 0;
	public int $sen = 0;
	public int $sta = 0;
	public int $str = 0;

	public function add(Ability $ability, int $amount): int {
		return match ($ability) {
			Ability::Agility => $this->agi += $amount,
			Ability::Intelligence => $this->int += $amount,
			Ability::Psychic => $this->psy += $amount,
			Ability::Sense => $this->sen += $amount,
			Ability::Stamina => $this->sta += $amount,
			Ability::Strength => $this->str += $amount,
		};
	}
}
