<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

class LeaderSetEvent extends LeaderEvent {
	/** @param string $player The names of the new leader */
	public function __construct(
		public string $player,
	) {
		$this->type = "leader(set)";
	}
}
