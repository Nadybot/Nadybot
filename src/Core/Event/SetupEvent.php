<?php declare(strict_types=1);

namespace Nadybot\Core;

class SetupEvent extends Event {
	public function __construct() {
		$this->type = "setup";
	}
}
