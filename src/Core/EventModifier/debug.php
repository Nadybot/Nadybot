<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use function Safe\json_encode;

use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\{
	Attributes as NCA,
	EventModifier,
	MessageHub,
	Routing\RoutableEvent,
	Text,
};

#[
	NCA\EventModifier(
		name: "debug",
		description: "This modifier allows you to modify the message of an\n".
			"event by replacing text, or adding a prefix."
	),
	NCA\Param(
		name: "to",
		type: "string",
		description: "If set to true, do a regular expression search and replace",
		required: true
	)
]
class Debug implements EventModifier {
	#[NCA\Inject]
	public MessageHub $msgHub;

	#[NCA\Inject]
	public Text $text;

	public function __construct(
		protected string $sendTo,
	) {
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		$receiver = $this->msgHub->getReceiver($this->sendTo);
		if (!isset($receiver)) {
			return $event;
		}
		$msgs = (array)$this->text->makeBlob(
			"Debug message",
			json_encode($event, JSON_PRETTY_PRINT, 512)
		);
		foreach ($msgs as $msg) {
			$r = new RoutableMessage($msg);
			$receiver->receive($r, $this->sendTo);
		}
		return $event;
	}
}
