<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\EventModifier;
use Nadybot\Core\Routing\RoutableEvent;

/**
 * @EventModifier("remove-popups")
 * @Description("This modifier will remove all popups and only
 *	leave the link name.")
 */
class RemovePopups implements EventModifier {
	public function __construct() {
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		// We only require prefixes for messages, the rest is passed through
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		$message = $event->getData();
		$message = preg_replace_callback(
			"/<a\s+href\s*=\s*([\"'])text:\/\/(.+?)\\1\s*>(.*?)<\/a>/is",
			function (array $matches) use (&$parts): string {
				return $matches[3];
			},
			$message
		);
		$modifiedEvent = clone $event;
		$modifiedEvent->setData($message);
		return $modifiedEvent;
	}
}
