<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\LostEden;

/**
 * A character in Lost Eden's directory API
 *
 * All game values are null for a character the game never saved
 */
class Character {
	public function __construct(
		public int $id,
		public string $name,
		public string $updatedAt,
		public string $firstName='',
		public string $lastName='',
		public ?int $level=null,
		public ?int $titleLevel=null,
		public ?int $alienLevel=null,
		public ?string $profession=null,
		public ?string $breed=null,
		public ?string $gender=null,
		public ?string $faction=null,
		public ?int $headId=null,
		public ?int $pvpRating=null,
		public ?CharacterOrg $org=null,
	) {
	}
}
