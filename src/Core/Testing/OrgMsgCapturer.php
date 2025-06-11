<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use Nadybot\Core\{
	EventManager,
	Events\OrgMsgChannelMsgEvent,
};

/** An interface used to capture org messages received by the bot */
class OrgMsgCapturer implements CapturerInterface {
	/** The captured output string */
	private string $output = '';

	/** {@inheritDoc} */
	public function register(EventManager $eventManager): void {
		$eventManager->subscribe(OrgMsgChannelMsgEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function unregister(EventManager $eventManager): void {
		$eventManager->unsubscribe(OrgMsgChannelMsgEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function getOutput(): string {
		return $this->output;
	}

	/** Capture the output whenever we send messages to the org chat */
	private function captureEvent(OrgMsgChannelMsgEvent $event): void {
		$this->output .= $event->message;
	}
}
