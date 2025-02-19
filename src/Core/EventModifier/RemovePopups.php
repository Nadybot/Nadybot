<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	Routing\Events\Base,
	Routing\RoutableEvent,
	Text,
	Types\EventModifier,
};

#[
	NCA\EventModifier(name: 'remove-popups'),
	NCA\Param(
		name: 'remove-links',
		type: 'bool',
		description: 'Also try to remove the text of the link to the popup',
		required: false
	)
]
/**
 * This modifier will remove all popups and only
 * leave the link name.
 */
class RemovePopups implements EventModifier {
	public function __construct(
		protected bool $removeLinks=false
	) {
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		if ($event->getEvent() !== $event::TYPE_MESSAGE) {
			$message = $event->getData()->message??null;
			if (!isset($message)) {
				return $event;
			}
			$message = Text::removePopups($message, $this->removeLinks);
			$modifiedEvent = clone $event;
			if (isset($modifiedEvent->data) && ($modifiedEvent->data instanceof Base)) {
				$modifiedEvent->data->message = $message;
			}
			return $modifiedEvent;
		}
		$message = $event->getData();
		if (!isset($message)) {
			return null;
		}
		$message = Text::removePopups($message, $this->removeLinks);
		$modifiedEvent = clone $event;
		$modifiedEvent->setData($message);
		return $modifiedEvent;
	}
}
