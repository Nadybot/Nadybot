<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\LostEden;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

/** An organization in Lost Eden's directory API */
class Org {
	/**
	 * @param OrgMember[] $members
	 *
	 * @psalm-param list<OrgMember> $members
	 */
	public function __construct(
		public int $id,
		public string $name,
		public string $updatedAt,
		public ?string $faction=null,
		public ?string $governingForm=null,
		public int $memberCount=0,
		#[CastListToType(OrgMember::class)] public array $members=[],
		public bool $truncated=false,
	) {
	}
}
