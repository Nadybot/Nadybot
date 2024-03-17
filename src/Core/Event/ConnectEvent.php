<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

use Nadybot\Core\Event;

class ConnectEvent extends Event {
	public function __construct() {
		$this->type = "connect";
	}
}
