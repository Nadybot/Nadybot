<?php declare(strict_types=1);

namespace Nadybot\Core;

class EventFeedReconnect extends Event {
	public function __construct() {
		$this->type = "event-feed-reconnect";
	}
}
