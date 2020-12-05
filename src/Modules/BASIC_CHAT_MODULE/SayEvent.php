<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event;

class SayEvent extends Event {
	/**
	 * The names of the sender
	 */
	public string $player;
	/**
	 * The message that was sent
	 */
	public string $message;
}
