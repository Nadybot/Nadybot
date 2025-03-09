<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use AO\Utils;
use Nadybot\Core\EventManager;
use Nadybot\Core\Events\SendPrivEvent;

/** An interface used to capture private channel messages sent by the bot */
class PrivateChannelCapturer implements CapturerInterface {
	private string $output = '';
	private readonly string $target;

	public function __construct(
		string $target
	) {
		$this->target = Utils::normalizeCharacter($target);
	}

	public function register(EventManager $eventManager): void {
		$eventManager->subscribe(SendPrivEvent::class, $this->captureEvent(...));
	}

	public function unregister(EventManager $eventManager): void {
		$eventManager->unsubscribe(SendPrivEvent::class, $this->captureEvent(...));
	}

	public function getOutput(): string {
		return $this->output;
	}

	private function captureEvent(SendPrivEvent $event): void {
		if ($event->channel !== $this->target) {
			return;
		}
		$this->output .= $event->message;
	}
}
