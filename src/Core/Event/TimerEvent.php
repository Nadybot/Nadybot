<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

use Nadybot\Core\Event;

class TimerEvent extends Event {
	public function __construct(int $time) {
		$this->type = (string)$time;
	}
}
