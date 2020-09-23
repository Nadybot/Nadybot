<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class TopicEvent extends Event {
	/**
	 * The names of the sender
	 */
	public string $player;
	/**
	 * The topic that was set or unset if cleared
	 */
	public string $topic;
}