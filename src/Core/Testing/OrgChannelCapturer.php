<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use Nadybot\Core\EventManager;
use Nadybot\Core\Events\SendGuildEvent;

/** An interface used to capture org channel messages sent by the bot */
class OrgChannelCapturer implements CapturerInterface {
	private string $output = '';

	public function register(EventManager $eventManager): void {
		$eventManager->subscribe(SendGuildEvent::class, $this->captureEvent(...));
	}

	public function unregister(EventManager $eventManager): void {
		$eventManager->unsubscribe(SendGuildEvent::class, $this->captureEvent(...));
	}

	public function getOutput(): string {
		return $this->output;
	}

	private function captureEvent(SendGuildEvent $event): void {
		$this->output .= $event->message;
	}
}
