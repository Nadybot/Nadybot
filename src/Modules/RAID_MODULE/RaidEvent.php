<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'raid(*)')]
abstract class RaidEvent {
	public function __construct(
		public Raid $raid,
		public string $player
	) {
	}
}
