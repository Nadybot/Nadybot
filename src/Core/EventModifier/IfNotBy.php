<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\Types\ParamType;
use Nadybot\Core\{
	Attributes as NCA,
	Routing\RoutableEvent,
	Types\EventModifier,
};

/**
 * This modifier will only route messages that are
 * not sent by a given person or group of people.
 */
#[NCA\EventModifier(name: 'if-not-by')]
class IfNotBy implements EventModifier {
	/** @var list<string> */
	protected array $senders = [];

	/**
	 * @param list<string> $senders The name of the character (case-insensitive)
	 * @param bool         $inverse If set to true, this will inverse the logic
	 *                              and drop all messages not by the given sender.
	 */
	public function __construct(
		#[
			NCA\Param(name: 'sender', type: ParamType::StringArray)
		] array $senders,
		#[NCA\Param] public bool $inverse=false
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
