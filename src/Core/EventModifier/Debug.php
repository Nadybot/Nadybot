<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use function Safe\json_encode;

use Nadybot\Core\{
	Attributes as NCA,
	EventModifier,
	MessageHub,
	Routing\RoutableEvent,
	Routing\RoutableMessage,
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
		description: "The routing destination where to send debug information to.\n".
			"Can be anything from \"<symbol>route list dst\", e.g. aopriv or aotell(Nady)",
		required: true
	)
]
class Debug implements EventModifier {
	#[NCA\Inject]
	private MessageHub $msgHub;

	#[NCA\Inject]
	private Text $text;

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
