<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\AOChatEvent;

#[Event(mask: 'chat(web)')]
class AOWebChatEvent extends AOChatEvent {
	/**
	 * @param string           $sender The name of the sender of the message
	 * @param ?list<WebSource> $path
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
