<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\EventModifier;
use Nadybot\Core\Routing\RoutableEvent;

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
 *	name='trim',
 *	type='bool',
 *	description='Shall we trim the prefix? By default we do.',
 *	required=false
 * )
 */
class IfHasPrefix implements EventModifier {
	protected string $prefix = "-";
	protected bool $trim = true;

	public function __construct(string $prefix, bool $trim=true) {
		$this->prefix = $prefix;
		$this->trim = $trim;
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
		if (!$this->trim) {
			return $event;
		}
		$modifiedEvent = clone $event;
		$modifiedEvent->setData(ltrim(substr($message, strlen($this->prefix))));
		return $modifiedEvent;
	}
}
