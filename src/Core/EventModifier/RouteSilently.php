<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	Routing\RoutableEvent,
	Types\EventModifier,
};

/**
 * This modifier allows you to treat messages routed with it
 * as if they hadn't been routed at all, so all standard actions will
 * still apply to them.
 */
#[NCA\EventModifier(name: 'route-silently')]
class RouteSilently implements EventModifier {
	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		$modifiedEvent = clone $event;
		$modifiedEvent->routeSilently = true;
		return $modifiedEvent;
	}
}
