<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	EventModifier,
	Routing\Events\Base,
	Routing\RoutableEvent,
	Routing\Source,
};

#[
	NCA\EventModifier(
		name: 'if-has-prefix',
		description: "This modifier will only route messages if they start\n".
			"with a certain prefix. By default, this prefix will then be removed\n".
			"if it has been found.\n".
			"This allows you to only route messages that start with a dash or an\n".
			'asterisk from one channel to another.'
	),
	NCA\Param(
		name: 'prefix',
		type: 'string',
		description: 'The prefix string. If the message starts with this, it will be routed.',
		required: true
	),
	NCA\Param(
		name: 'for-relays',
		type: 'bool',
		description: 'If set to true, also require messages from the relay to have this prefix',
		required: false
	),
	NCA\Param(
		name: 'for-events',
		type: 'bool',
		description: "Determines if the optional message that an event can have is cleared unless\n".
			"it starts with the prefix. This does not alter the event itself, it will still\n".
			"be routed, but it will not generate a message.\n".
			"Common use case is not routing the online/offline messages via relays, but\n".
			'keeping the event itself to share online lists.',
		required: false
	),
	NCA\Param(
		name: 'trim',
		type: 'bool',
		description: 'Shall we trim the prefix? By default we do.',
		required: false
	),
	NCA\Param(
		name: 'inverse',
		type: 'bool',
		description: 'If set, filter out all messages starting with the prefix',
		required: false
	)
]
class IfHasPrefix implements EventModifier {
	protected string $prefix = '-';
	protected bool $trim = true;
	protected bool $inverse = false;
	protected bool $forEvents = true;
	protected bool $forRelays = false;

	public function __construct(string $prefix, bool $forRelays=false, bool $forEvents=true, bool $trim=true, bool $inverse=false) {
		$this->prefix = $prefix;
		$this->trim = $trim;
		$this->forRelays = $forRelays;
		$this->forEvents = $forEvents;
		$this->inverse = $inverse;
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return null;
		}
		// Events might have their default message modified
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			if (!$this->forEvents) {
				return $event;
			}
			$message = $event->getData()->message ?? null;
			$hasPrefix = isset($message) && (strncmp($message, $this->prefix, strlen($this->prefix)) === 0);
			if ($hasPrefix === $this->inverse) {
				$event = clone $event;
				if (isset($event->data) && ($event->data instanceof Base)) {
					$event->data->message = null;
				}
				return $event;
			}
			if (!$hasPrefix || !$this->trim) {
				return $event;
			}
			$event = clone $event;
			if (isset($event->data) && ($event->data instanceof Base)) {
				$event->data->message = ltrim(substr($message, strlen($this->prefix)));
			}
			return $event;
		}
		$fromRelay = isset($event->path[0]) && $event->path[0]->type === Source::RELAY;
		if ($fromRelay && !$this->forRelays) {
			return $event;
		}
		$message = $event->getData();
		$hasPrefix = isset($message) && (strncmp($message, $this->prefix, strlen($this->prefix)) === 0);
		if ($hasPrefix === $this->inverse) {
			return null;
		}
		if (!$hasPrefix || !$this->trim) {
			return $event;
		}
		$modifiedEvent = clone $event;
		$modifiedEvent->setData(ltrim(substr($message, strlen($this->prefix))));
		return $modifiedEvent;
	}
}
