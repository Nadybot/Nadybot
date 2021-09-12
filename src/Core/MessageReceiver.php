<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\RoutableEvent;

interface MessageReceiver extends MessageEmitter {

	/**
	 * Dispatch an event to the name $destination
	 *
	 * @param RoutableEvent $event The event to route
	 * @param string $destination If we routed to aotell(Nady), then $destination will be "Nady", otherwise the type
	 * @return bool Success or not
	 */
	public function receive(RoutableEvent $event, string $destination): bool;
}
