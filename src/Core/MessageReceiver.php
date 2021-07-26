<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\RoutableEvent;

interface MessageReceiver extends MessageEmitter {

	/** Dispatch an event */
	public function receive(RoutableEvent $event, string $destination): bool;
}
