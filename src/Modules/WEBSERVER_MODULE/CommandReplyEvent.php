<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Event;

class CommandReplyEvent extends Event {
	public const EVENT_MASK = 'cmdreply';

	/**
	 * @param string   $uuid For which WebsocketConnection is this destined
	 * @param string[] $msgs An array with reply messages
	 */
	public function __construct(
		public string $uuid,
		public array $msgs=[],
	) {
		$this->type = self::EVENT_MASK;
	}
}
