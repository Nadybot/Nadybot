<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\AOChatEvent;
use Nadybot\Core\Modules\DISCORD\DiscordMessageIn;
use Nadybot\Core\Types\EventInterface;

#[Event(mask: 'discord*')]
class DiscordMessageEvent extends AOChatEvent implements EventInterface {
	/**
	 * @param string  $sender  The name of the sender of the message
	 * @param string  $channel The name of the channel via which the message was sent
	 * @param string  $message The message itself
	 * @param ?string $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public readonly string $type,
		public string $sender,
		string $channel,
		string $message,
		public DiscordMessageIn $discord_message,
		?string $worker=null,
	) {
		parent::__construct(channel: $channel, message: $message, worker: $worker);
	}

	public function getEvent(): string {
		return $this->type;
	}
}
