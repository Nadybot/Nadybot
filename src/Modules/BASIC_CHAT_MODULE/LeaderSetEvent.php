<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

class LeaderSetEvent extends LeaderEvent {
	public const EVENT_MASK = "leader(set)";

	/** @param string $player The names of the new leader */
	public function __construct(
		public string $player,
	) {
		$this->type = self::EVENT_MASK;
	}
}
