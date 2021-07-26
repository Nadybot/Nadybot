<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\EventModifier;
use Nadybot\Core\Routing\RoutableEvent;

class RequirePrefix implements EventModifier {
	protected string $prefix = "-";

	public function __construct(string $prefix) {
		$this->prefix = $prefix;
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		// We only require prefixes for messages, the rest is passed through
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		$message = $event->getData();
		if (strncmp($message, $this->prefix, strlen($this->prefix)) !== 0) {
			return null;
		}
		$modifiedEvent = clone $event;
		$modifiedEvent->setData(ltrim(substr($message, strlen($this->prefix))));
		return $modifiedEvent;
	}
}
