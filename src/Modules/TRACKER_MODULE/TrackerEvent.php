<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\Event;

class TrackerEvent extends Event {
	public string $player;
}
