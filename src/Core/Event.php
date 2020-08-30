<?php declare(strict_types=1);

namespace Nadybot\Core;

class Event {
	/** Either the name of the sender or the numeric UID (eg. city raid accouncements) */
	public $sender;

	public string $type;

	public AOChatPacket $packet;

	public string $channel;

	public string $message;
}
