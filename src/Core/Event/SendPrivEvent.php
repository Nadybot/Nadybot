<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

/** We send a message to a private channel */
class SendPrivEvent extends AOChatEvent {
	public const EVENT_MASK = "sendpriv";

	/**
	 * @param string      $sender  Either the name of the sender or the numeric UID (eg. city raid accouncements)
	 * @param string      $channel The channel (msg, priv, guild) via which the message was sent
	 * @param string      $message The message itself
	 * @param null|string $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public string $message,
		public ?string $worker=null,
	) {
		$this->type = self::EVENT_MASK;
	}
}
