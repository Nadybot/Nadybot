<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

/** We send a tell */
class SendMsgEvent extends AOChatEvent {
	public const EVENT_MASK = 'sendmsg';

	/**
	 * @param string  $sender  Either the name of the sender or the numeric UID (eg. city raid accouncements)
	 * @param string  $channel The channel (msg, priv, guild) via which the message was sent
	 * @param string  $message The message itself
	 * @param ?string $worker  If set, this is the id of the worker via which the message was received
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
