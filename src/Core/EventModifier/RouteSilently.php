<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	EventModifier,
	Routing\RoutableEvent,
};

#[
	NCA\EventModifier(
		name: 'route-silently',
		description: "This modifier allows you to treat messages routed with it\n".
			"as if they had't been routed at all, so all standard actions will\n".
			'still apply to them.'
	),
]
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
