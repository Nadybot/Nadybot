<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

class RaidUnlockEvent extends RaidEvent {
	public const EVENT_MASK = "raid(unlock)";

	public function __construct(
		public Raid $raid,
		public string $player,
	) {
		$this->type = self::EVENT_MASK;
	}
}
