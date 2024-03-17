<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

class TopicClearEvent extends TopicEvent {
	/**
	 * @param string $player The names of the sender
	 * @param string $topic  The topic that was cleared
	 */
	public function __construct(
		public string $player,
		public string $topic,
	) {
		$this->type = "topic(set)";
	}
}
