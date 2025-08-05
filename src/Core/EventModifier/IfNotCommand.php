<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	Nadybot,
	Routing\RoutableEvent,
	SettingManager,
	Types\EventModifier,
};

/**
 * This modifier will only route messages that are
 * not a command or a reply to a command.
 */
#[NCA\EventModifier(name: 'if-not-command')]
class IfNotCommand implements EventModifier {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private SettingManager $settingManager;

	/** {@inheritDoc} */
	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		// We only require prefixes for messages, the rest is passed through
		if (!isset($event) || $event->getEvent() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		if (isset($event->char) && ($event->char->id === $this->chatBot->char?->id)) {
			return null;
		}
		$message = $event->getData();
		if (!isset($message) || !is_string($message)) {
			return null;
		}
		if ($message[0] === $this->settingManager->get('symbol') && strlen($message) > 1) {
			return null;
		}
		return $event;
	}
}
