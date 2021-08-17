<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\EventModifier;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;

/**
 * @EventModifier("if-has-prefix")
 * @Description("This modifier will only route messages if they start
 *	with a certain prefix. By default, this prefix will then be removed
 *	if it has been found.
 *	This allows you to only route messages that start with a dash or an
 *	asterisk from one channel to another.")
 * @Param(
 *	name='prefix',
 *	type='string',
 *	description='The prefix string. If the message starts with this, it will be routed.',
 *	required=true
 * )
 * @Param(
 *	name='for-relays',
 *	type='bool',
 *	description='If set to true, also require messages from the relay to have this prefix',
 *	required=false
 * )
 * @Param(
 *	name='trim',
 *	type='bool',
 *	description='Shall we trim the prefix? By default we do.',
 *	required=false
 * )
 * @Param(
 *	name='inverse',
 *	type='bool',
 *	description='If set, filter out all messages starting with the prefix',
 *	required=false
 * )
 */
class IfHasPrefix implements EventModifier {
	protected string $prefix = "-";
	protected bool $trim = true;
	protected bool $inverse = false;
	protected bool $forRelays = false;

	public function __construct(string $prefix, bool $forRelays=false, bool $trim=true, bool $inverse=false) {
		$this->prefix = $prefix;
		$this->trim = $trim;
		$this->forRelays = $forRelays;
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return null;
		}
		// We only require prefixes for messages, the rest is passed through
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		$fromRelay = isset($event->path[0]) && $event->path[0]->type === Source::RELAY;
		if ($fromRelay && !$this->forRelays) {
			return $event;
		}
		$message = $event->getData();
		$hasPrefix = (strncmp($message, $this->prefix, strlen($this->prefix)) !== 0);
		if ($hasPrefix !== $this->inverse) {
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
