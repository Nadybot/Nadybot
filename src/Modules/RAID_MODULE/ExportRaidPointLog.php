<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\ExportCharacter;

class ExportRaidPointLog {
	/**
	 * @param ExportCharacter  $character         The character who got or lost raid points
	 * @param float            $raidPoints        How many raid points given or taken
	 * @param ?int             $time              Time when the change occurred
	 * @param ?ExportCharacter $givenBy           Who gave the raid points?
	 * @param ?string          $reason            Why were the raid points given?
	 * @param ?bool            $givenByTick       True if the raidpoints were automatically given for raid participation
	 * @param ?bool            $givenIndividually True if these points were given to only this character, false if to the whole raidforce
	 * @param ?string          $raidId            If these points were given during a raid, this is the raid's ID
	 */
	public function __construct(
		public ExportCharacter $character,
		public float $raidPoints,
		public ?int $time=null,
		public ?ExportCharacter $givenBy=null,
		public ?string $reason=null,
		public ?bool $givenByTick=null,
		public ?bool $givenIndividually=null,
		public ?string $raidId=null,
	) {
	}
}
