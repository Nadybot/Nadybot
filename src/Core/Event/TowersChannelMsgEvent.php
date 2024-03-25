<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

class TowersChannelMsgEvent extends PublicChannelMsgEvent {
	public const EVENT_MASK = 'towers';

	/**
	 * @param string  $channel The name of the public channel via which the message was sent
	 * @param string  $message The message itself
	 * @param string  $sender  The name of the sender of the message
	 * @param ?string $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public string $channel,
		public string $message,
		public ?string $sender=null,
		public ?string $worker=null,
	) {
		$this->type = self::EVENT_MASK;
	}
}
