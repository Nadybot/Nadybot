<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

use Nadybot\Core\Event;

class SetupEvent extends Event {
	public function __construct() {
		$this->type = "setup";
	}
}
