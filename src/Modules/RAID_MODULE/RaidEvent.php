<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Event;

class RaidEvent extends Event {
	public Raid $raid;
	public string $player;

	public function __construct(Raid $raid) {
		$this->raid = $raid;
	}
}
