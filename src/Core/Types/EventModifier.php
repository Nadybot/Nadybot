<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Nadybot\Core\Routing\RoutableEvent;

/**
 * EventModifier is an interface for classes that modify events.
 * They can be used to modify events before they are dispatched,
 * and can be configured dynamically with bot commands.
 */
interface EventModifier {
	/**
	 * Modify the given event and return a new, modified one.
	 *
	 * @param ?RoutableEvent $event The event to modify, or null if it was discarded
	 *
	 * @return ?RoutableEvent A new, modified event, or the original event,
	 *                        if no modification was done. Return null to completely
	 *                        discard the event
	 */
	public function modify(?RoutableEvent $event=null): ?RoutableEvent;
}
