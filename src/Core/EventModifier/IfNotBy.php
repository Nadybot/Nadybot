<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	Routing\RoutableEvent,
	Types\EventModifier,
};

#[
	NCA\EventModifier(name: 'if-not-by'),
	NCA\Param(
		name: 'sender',
		type: 'string[]',
		description: 'The name of the character (case-insensitive)',
		required: true
	),
	NCA\Param(
		name: 'inverse',
		type: 'bool',
		description: "If set to true, this will inverse the logic\n".
			'and drop all messages not by the given sender.',
		required: false
	)
]
/**
 * This modifier will only route messages that are
 * not sent by a given person or group of people.
 */
class IfNotBy implements EventModifier {
	/** @var list<string> */
	protected array $senders = [];

	/** @param list<string> $senders */
	public function __construct(
		array $senders,
		public bool $inverse=false
	) {
		$this->senders = array_map('strtolower', $senders);
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		// We only require prefixes for messages, the rest is passed through
		if ($event->getEvent() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		$matches = isset($event->char) && in_array(strtolower($event->char->name), $this->senders, true);
		if ($matches === $this->inverse) {
			return $event;
		}
		return null;
	}
}
