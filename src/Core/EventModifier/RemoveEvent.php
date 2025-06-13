<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	EventManager,
	Routing\RoutableEvent,
	Types\EventModifier,
};
use Nadybot\Core\Types\ParamType;

/**
 * This modifier removes events of one or more types
 * from being routed. A typical event is "online" which is triggered
 * whenever a character goes online or offline.
 * To stop displaying logon/logoff messages from your relay, add
 * remove-event(type=online from="relay(*)") to your stack.
 */
#[NCA\EventModifier(name: 'remove-event')]
class RemoveEvent implements EventModifier {
	/**
	 * @param list<string> $filter The event type to remove. This parameter can be used more than once to filter out more than one type
	 * @param null|string  $from   If set, this filter will only remove these events if the source matches
	 *                             this parameter. This can be useful for filtering out the routing of online
	 *                             events only from the relay to org or priv channel - not the other way around.
	 *                             Of course you can use wildcards such as relay(*) here.
	 */
	public function __construct(
		#[NCA\Param(name: 'type', type: ParamType::StringArray)] protected array $filter,
		#[NCA\Param] protected ?string $from=null
	) {
	}

	/** {@inheritDoc} */
	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return null;
		}
		if ($event->type !== $event::TYPE_EVENT) {
			return $event;
		}
		if (!is_object($event->data)) {
			return $event;
		}
		$dataEventType = EventManager::getEventType($event->data);
		if (!in_array($dataEventType, $this->filter, true)) {
			return $event;
		}
		if (!isset($this->from) || !count($event->path)) {
			return null;
		}
		$source = "{$event->path[0]->type}({$event->path[0]->name})";
		if (fnmatch($this->from, $source, \FNM_CASEFOLD)) {
			return null;
		}
		return $event;
	}
}
