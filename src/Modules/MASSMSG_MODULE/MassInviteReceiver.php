<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE;

use AO\Package;
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
 * This class accepts incoming messages and sends them out as mass invites
 *
 * @package Nadybot\Modules\MASSMSG_MODULE
 */
class MassInviteReceiver implements MessageReceiver {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private MassMsgController $massMsgCtrl;

	public function getChannelName(): string {
		return Source::SYSTEM . '(mass-invite)';
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
				MassMsgController::PREF_INVITES => function (string $name): void {
					if (null === ($uid = $this->chatBot->getUid($name))) {
						return;
					}
					$this->chatBot->sendPackage(
						package: new Package\Out\PrivateChannelInvite(charId: $uid)
					);
				},
			]
		);
		return true;
	}
}
