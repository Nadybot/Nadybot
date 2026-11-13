<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\LostEden;

/** A member of an organization in Lost Eden's directory API */
class OrgMember {
	public function __construct(
		public int $id,
		public string $name,
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
		public ?int $rank=null,
		public ?string $rankTitle=null,
	) {
	}
}
