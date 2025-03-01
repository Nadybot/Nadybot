<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'cmdreply')]
class CommandReplyEvent {
	/**
	 * @param string       $uuid For which WebsocketConnection is this destined
	 * @param list<string> $msgs An array with reply messages
	 */
	public function __construct(
		public string $uuid,
		public array $msgs=[],
	) {
	}
}
