<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes as NCA;

#[NCA\Event(mask: 'timer(*)')]
class TimerEvent extends Event {
	public function __construct(int $time) {
		parent::__construct(type: "timer({$time})");
	}
}
