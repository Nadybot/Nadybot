<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\AOChatEvent;

#[Event(mask: 'chat(web)')]
class AOWebChatEvent extends AOChatEvent {
	public const EVENT_MASK = 'chat(web)';

	/**
	 * @param string           $sender  The name of the sender of the message
	 * @param string           $channel The name of the channel via which the message was sent
	 * @param string           $message The message itself
	 * @param ?list<WebSource> $path
	 * @param ?string          $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public string $sender,
		string $channel,
		string $message,
		public string $color,
		public ?array $path=null,
		?string $worker=null,
	) {
		parent::__construct(channel: $channel, message: $message, worker: $worker);
	}
}
