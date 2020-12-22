<?php declare(strict_types=1);

namespace Nadybot\Core;

class AOChatEvent extends Event {
	/** Either the name of the sender or the numeric UID (eg. city raid accouncements) */
	public $sender;

	/** The channel (msg, priv, guild) via which the message was sent */
	public string $channel;

	/** The message itself */
	public string $message;

	/** If set, this is the id of the worker via which the message was received */
	public ?int $worker = null;
}
