<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

class LeaderClearEvent extends LeaderEvent {
	/** @param string $player The names of the old leader */
	public function __construct(
		public string $player,
	) {
		$this->type = "leader(clear)";
	}
}
