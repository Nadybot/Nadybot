<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\Event;

abstract class TrackerEvent extends Event {
	public const EVENT_MASK = 'tracker(*)';

	public string $player;
	public int $uid;
}
