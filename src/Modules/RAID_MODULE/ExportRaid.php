<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class ExportRaid {
	/**
	 * @param ?string            $raidId               The internal raid number, used so it can be referenced by the auctions
	 * @param ?int               $time                 When did the raid start?
	 * @param ?string            $raidDescription      What was raided?
	 * @param ?bool              $raidLocked           Is/was the raid locked and only raid leaders were allowed to add raiders?
	 * @param ?int               $raidAnnounceInterval How many seconds between announcing the raid?
	 * @param ?int               $raidSecondsPerPoint  If this is set, then raiders are automatically awarded raid points and this is the interval between receiving 1 point.
	 * @param ?ExportRaider[]    $raiders              A list of all raiders who, at one point, were in the raid
	 * @param ?ExportRaidState[] $history              A list of all raiders who, at one point, were in the raid
	 *
	 * @psalm-param ?list<ExportRaider>    $raiders
	 * @psalm-param ?list<ExportRaidState> $history
	 */
	public function __construct(
		public ?string $raidId=null,
		public ?int $time=null,
		public ?string $raidDescription=null,
		public ?bool $raidLocked=null,
		public ?int $raidAnnounceInterval=null,
		public ?int $raidSecondsPerPoint=null,
		#[CastListToType(ExportRaider::class)] public ?array $raiders=null,
		#[CastListToType(ExportRaidState::class)] public ?array $history=null,
	) {
	}
}
