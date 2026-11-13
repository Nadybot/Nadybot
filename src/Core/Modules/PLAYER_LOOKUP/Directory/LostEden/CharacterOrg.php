<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\LostEden;

/** The org membership of a character in Lost Eden's directory API */
class CharacterOrg {
	public function __construct(
		public int $id,
		public string $name,
		public ?int $rank=null,
		public ?string $rankTitle=null,
	) {
	}
}
