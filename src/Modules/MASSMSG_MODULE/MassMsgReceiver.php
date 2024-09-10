<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	Nadybot,
	Routing\Events\Base,
	Routing\RoutableEvent,
	Routing\Source,
	Types\MessageReceiver,
};
use Revolt\EventLoop;

/**
 * This class accepts incoming messages and sends them out as mass messages
 *
 * @package Nadybot\Modules\MASSMSG_MODULE
 */
class MassMsgReceiver implements MessageReceiver {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private MassMsgController $massMsgCtrl;

	public function getChannelName(): string {
		return Source::SYSTEM . '(mass-message)';
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			$baseEvent = $event->data??null;
			if (!isset($baseEvent) || !($baseEvent instanceof Base) || !isset($baseEvent->message)) {
				return false;
			}
			$msg = $baseEvent->message;
		} else {
			$msg = (string)$event->getData();
		}
		$ctrl = $this->massMsgCtrl;
		if (($ctrl->massMsgRateLimitCheck()) !== null) {
			return false;
		}
		$message = "{$ctrl->massmsgColor}{$msg}<end>".
			' :: ' . $ctrl->getMassMsgOptInOutBlob();

		EventLoop::queue(
			$ctrl->massCallback(...),
			[
				MassMsgController::PREF_MSGS => function (string $name) use ($message): void {
					$this->chatBot->sendMassTell($message, $name);
				},
			]
		);
		return true;
	}
}
