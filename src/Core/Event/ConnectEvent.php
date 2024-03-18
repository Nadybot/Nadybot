<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

use Nadybot\Core\Event;

class ConnectEvent extends Event {
	public const EVENT_MASK = "connect";

	public function __construct() {
		$this->type = self::EVENT_MASK;
	}
}
