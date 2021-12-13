<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\EventModifier;
use Nadybot\Core\Routing\RoutableEvent;

#[
	NCA\EventModifier("if-not-by"),
	NCA\Description("This modifier will only route messages that are\n".
		"not sent by a given person or group of people."),
	NCA\Param(
		name: "sender",
		type: "string[]",
		description: "The name of the character (case-insensitive)",
		required: true
	),
	NCA\Param(
		name: "inverse",
		type: "bool",
		description: "If set to true, this will inverse the logic\n".
			"and drop all messages not by the given sender.",
		required: false
	)
]
class IfNotBy implements EventModifier {
	/** @var string[] */
	protected array $senders = [];
	protected bool $inverse;

	public function __construct(array $senders, bool $inverse=false) {
		$this->senders = array_map("strtolower", $senders);
		$this->inverse = $inverse;
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		// We only require prefixes for messages, the rest is passed through
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		$matches = isset($event->char) && in_array(strtolower($event->char->name), $this->senders);
		if ($matches === $this->inverse) {
			return $event;
		}
		return null;
	}
}
