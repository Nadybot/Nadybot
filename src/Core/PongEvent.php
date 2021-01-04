<?php declare(strict_types=1);

namespace Nadybot\Core;

class PongEvent extends Event {
	/** Which worker received the pong */
	public int $worker = 0;

	public function __construct(int $worker=0) {
		$this->type = "pong";
		$this->worker = $worker;
	}
}
