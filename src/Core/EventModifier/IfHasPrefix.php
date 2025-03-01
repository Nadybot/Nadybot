<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	Routing\Events\Base,
	Routing\RoutableEvent,
	Routing\Source,
	Types\EventModifier,
};

/**
 * This modifier will only route messages if they start with a
 * certain prefix. By default, this prefix will then be removed
 * if it has been found.
 * This allows you to only route messages that start with a dash or an
 * asterisk from one channel to another.
 */
#[NCA\EventModifier(name: 'if-has-prefix')]
class IfHasPrefix implements EventModifier {
	/**
	 * @param string $prefix    The prefix string. If the message starts with this, it will be routed.
	 * @param bool   $forRelays If set to true, also require messages from the relay to have this prefix
	 * @param bool   $forEvents Determines if the optional message that an event can have is cleared unless
	 *                          it starts with the prefix.
	 *                          This does not alter the event itself, it will still be routed,
	 *                          but it will not generate a message.
	 *                          Common use case is not routing the online/offline messages via relays, but
	 *                          keeping the event itself to share online lists.
	 * @param bool   $trim      Shall we trim the prefix? By default we do.
	 * @param bool   $inverse   If set, filter out all messages starting with the prefix
	 */
	public function __construct(
		#[NCA\Param] protected string $prefix,
		#[NCA\Param(name: 'for-relays')] protected bool $forRelays=false,
		#[NCA\Param(name: 'for-events')] protected bool $forEvents=true,
		#[NCA\Param] protected bool $trim=true,
		#[NCA\Param] protected bool $inverse=false
	) {
	}

	/** {@inheritDoc} */
	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return null;
		}
		// Events might have their default message modified
		if ($event->getEvent() !== $event::TYPE_MESSAGE) {
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
