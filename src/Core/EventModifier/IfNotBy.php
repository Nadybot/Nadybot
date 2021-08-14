<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\EventModifier;
use Nadybot\Core\Routing\RoutableEvent;

/**
 * @EventModifier("if-not-by")
 * @Description("This modifier will only route messages that are
 *	not sent by a given person.")
 * @Param(
 *	name='sender',
 *	type='string',
 *	description='The name of the character (case-insensitive)',
 *	required=true
 * )
 * @Param(
 *	name='inverse',
 *	type='bool',
 *	description='If set to true, this will inverse the logic
 *	and drop all messages not by the given sender.',
 *	required=false
 * )
 */
class IfNotBy implements EventModifier {
	protected string $sender;
	protected bool $inverse;

	public function __construct(string $sender, bool $inverse=false) {
		$this->sender = strtolower($sender);
		$this->inverse = $inverse;
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		// We only require prefixes for messages, the rest is passed through
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		$matches = isset($char) && strtolower($event->char->name) === $this->sender;
		if ($matches === $this->inverse) {
			return $event;
		}
		return null;
	}
}
