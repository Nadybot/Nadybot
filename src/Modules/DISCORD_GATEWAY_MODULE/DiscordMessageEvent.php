<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Event\AOChatEvent;
use Nadybot\Core\Modules\DISCORD\DiscordMessageIn;

class DiscordMessageEvent extends AOChatEvent {
	public const EVENT_MASK = 'discordpriv';

	/**
	 * @param string  $sender  The name of the sender of the message
	 * @param string  $channel The name of the channel via which the message was sent
	 * @param string  $message The message itself
	 * @param ?string $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public string $type,
		public string $sender,
		public string $channel,
		public string $message,
		public DiscordMessageIn $discord_message,
		public ?string $worker=null,
	) {
		$this->type = self::EVENT_MASK;
	}
}
