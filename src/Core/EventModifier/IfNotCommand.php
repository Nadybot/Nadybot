<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	EventModifier,
	Nadybot,
	Routing\RoutableEvent,
	SettingManager,
};

#[
	NCA\EventModifier(
		name: "if-not-command",
		description:
			"This modifier will only route messages that are\n".
			"not a command or a reply to a command."
	)
]
class IfNotCommand implements EventModifier {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public SettingManager $settingManager;

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		// We only require prefixes for messages, the rest is passed through
		if (!isset($event) || $event->getType() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		if (isset($event->char) && ($event->char->id === $this->chatBot->char->id)) {
			return null;
		}
		$message = $event->getData();
		if (!isset($message)) {
			return null;
		}
		if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
			return null;
		}
		return $event;
	}
}
