<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Event;

class CommandReplyEvent extends Event {
	/**
	 * An array with reply messages
	 * @var string[]
	 */
	public array $msgs = [];

	/** For which WebsocketConnection is this destined */
	public string $uuid;
}
