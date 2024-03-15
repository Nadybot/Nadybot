<?php declare(strict_types=1);

namespace Nadybot\Core;

class TimerEvent extends Event {
	public function __construct(int $time) {
		$this->type = (string)$time;
	}
}
