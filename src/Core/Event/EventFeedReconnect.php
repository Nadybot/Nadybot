<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

use Nadybot\Core\Event;

class EventFeedReconnect extends Event {
	public const EVENT_MASK = 'event-feed-reconnect';

	public function __construct() {
		$this->type = self::EVENT_MASK;
	}
}
