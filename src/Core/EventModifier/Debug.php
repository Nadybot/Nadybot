<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use function Safe\json_encode;

use Nadybot\Core\{
	Attributes as NCA,
	MessageHub,
	Routing\RoutableEvent,
	Routing\RoutableMessage,
	Text,
	Types\EventModifier,
};

/**
 * This modifier allows you to modify the message of an
 * event by replacing text, or adding a prefix.
 */
#[NCA\EventModifier(name: 'debug')]
class Debug implements EventModifier {
	#[NCA\Inject]
	private MessageHub $msgHub;

	/**
	 * @param string $sendTo The routing destination where to send debug information to.
	 *                       Can be anything from "<symbol>route list dst", e.g. aopriv or aotell(Nady)
	 */
	public function __construct(
		#[NCA\Param(name: 'to')] protected string $sendTo,
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
		$msg = Text::makeBlob(
			'Debug message',
			json_encode($event, \JSON_PRETTY_PRINT, 512)
		);
		$r = new RoutableMessage($msg);
		$receiver->receive($r, $this->sendTo);
		return $event;
	}
}
