<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	EventModifier,
	Routing\Events\Base,
	Routing\RoutableEvent,
	Text,
};

#[
	NCA\EventModifier(
		name: 'remove-popups',
		description: "This modifier will remove all popups and only\n".
			'leave the link name.'
	),
	NCA\Param(
		name: 'remove-links',
		type: 'bool',
		description: 'Also try to remove the text of the link to the popup',
		required: false
	)
]
class RemovePopups implements EventModifier {
	protected bool $removeLinks = false;
	#[NCA\Inject]
	private Text $text;

	public function __construct(bool $removeLinks=false) {
		$this->removeLinks = $removeLinks;
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			$message = $event->getData()->message??null;
			if (!isset($message)) {
				return $event;
			}
			$message = $this->text->removePopups($message, $this->removeLinks);
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
		$message = $this->text->removePopups($message, $this->removeLinks);
		$modifiedEvent = clone $event;
		$modifiedEvent->setData($message);
		return $modifiedEvent;
	}
}
