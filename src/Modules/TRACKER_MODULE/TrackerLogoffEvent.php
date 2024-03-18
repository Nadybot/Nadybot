<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

class TrackerLogoffEvent extends TrackerEvent {
	public const EVENT_MASK = "tracker(logoff)";

	public function __construct(
		public string $player,
		public int $uid,
	) {
		$this->type = self::EVENT_MASK;
	}
}
