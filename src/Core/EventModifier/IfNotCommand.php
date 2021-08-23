<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\EventModifier;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\SettingManager;

/**
 * @EventModifier("if-not-command")
 * @Description("This modifier will only route messages that are
 *	not a command or a reply to a command.")
 */
class IfNotCommand implements EventModifier {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		// We only require prefixes for messages, the rest is passed through
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		if (isset($event->char) && ($event->char->id === $this->chatBot->char->id)) {
			return null;
		}
		$message = $event->getData();
		if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
			return null;
		}
		return $event;
	}
}
