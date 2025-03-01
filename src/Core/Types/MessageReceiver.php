<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Nadybot\Core\Routing\RoutableEvent;

/**
 * This interface is used to handle RoutableEvent messages sent to this object.
 */
interface MessageReceiver extends MessageEmitter {
	/**
	 * Dispatch an event to the name $destination
	 *
	 * @param RoutableEvent $event       The event to route
	 * @param string        $destination If we routed to aotell(Nady), then $destination will be "Nady", otherwise the type
	 *
	 * @return bool Success or not
	 */
	public function receive(RoutableEvent $event, string $destination): bool;
}
