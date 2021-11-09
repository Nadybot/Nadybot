<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\EventModifier;
use Nadybot\Core\Routing\RoutableEvent;

/**
 * @EventModifier("remove-event")
 * @Description('This modifier removes events of one or more types
 *	from being routed. A typical event is "online" which is triggered
 *	whenever a character goes online or offline.
 *	To stop displaying logon/logoff messages from your relay, add
 *	remove-event(type=online from="relay(*)") to your stack.');
 * @Param(
 *	name='type',
 *	type='string[]',
 *	description='The event type to remove. This parameter can be used more than once to filter out more than one type',
 *	required=true
 * )
 * @Param(
 *	name='from',
 *	type='string',
 *	description='If set, this filter will only remove these events if the source matches
 *	this parameter. This can be useful for filtering out the routing of online
 *	events only from the relay to org or priv channel - not the other way around.
 *	Of course you can use wildcards such as relay(*) here.',
 *	required=false
 * )
 */
class RemoveEvent implements EventModifier {
	protected array $filter = [];
	protected ?string $from;

	public function __construct(array $filter, ?string $from=null) {
		$this->filter = $filter;
		$this->from = $from;
	}

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
		if (!in_array($event->data->type, $this->filter)) {
			return $event;
		}
		if (!isset($this->from) || empty($event->path)) {
			return null;
		}
		$source = "{$event->path[0]->type}({$event->path[0]->name})";
		if (fnmatch($this->from, $source, FNM_CASEFOLD)) {
			return null;
		}
		return $event;
	}
}
