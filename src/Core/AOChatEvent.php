<?php declare(strict_types=1);

namespace Nadybot\Core;

class AOChatEvent extends Event {
	/** Either the name of the sender or the numeric UID (eg. city raid accouncements) */
	public $sender;

	public string $channel;

	public string $message;
}
