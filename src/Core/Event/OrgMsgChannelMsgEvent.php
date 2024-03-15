<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

class OrgMsgChannelMsgEvent extends PublicChannelMsgEvent {
	/**
	 * @param string  $sender  The name of the sender of the message
	 * @param string  $channel The name of the public channel via which the message was sent
	 * @param string  $message The message itself
	 * @param ?string $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public string $message,
		public ?string $worker=null,
	) {
		$this->type = "orgmsg";
	}
}
