<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'topic(*)')]
abstract class TopicEvent {
	/**
	 * @param string $player The names of the sender
	 * @param string $topic  The topic that was set or unset if cleared
	 */
	public function __construct(
		public string $player,
		public string $topic,
	) {
	}
}
