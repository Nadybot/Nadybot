<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use Nadybot\Core\{
	EventManager,
	Events\GuildChannelMsgEvent,
	Events\SendGuildEvent,
};

/** An interface used to capture org channel messages sent by the bot */
class OrgChannelCapturer implements CapturerInterface {
	/** The captured output string */
	private string $output = '';

	/** {@inheritDoc} */
	public function register(EventManager $eventManager): void {
		$eventManager->subscribe(SendGuildEvent::class, $this->captureEvent(...));
		$eventManager->subscribe(GuildChannelMsgEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function unregister(EventManager $eventManager): void {
		$eventManager->unsubscribe(SendGuildEvent::class, $this->captureEvent(...));
		$eventManager->unsubscribe(GuildChannelMsgEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function getOutput(): string {
		return $this->output;
	}

	/** Capture the output whenever we send messages to the org chat */
	private function captureEvent(SendGuildEvent|GuildChannelMsgEvent $event): void {
		$this->output .= $event->message;
	}
}
