<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use AO\Utils;
use Nadybot\Core\EventManager;
use Nadybot\Core\Events\SendMsgEvent;

/** An interface used to capture tell messages sent by the bot */
class TellCapturer implements CapturerInterface {
	private string $output = '';
	private readonly string $target;

	public function __construct(
		string $target
	) {
		$this->target = Utils::normalizeCharacter($target);
	}

	public function register(EventManager $eventManager): void {
		$eventManager->subscribe(SendMsgEvent::class, $this->captureEvent(...));
	}

	public function unregister(EventManager $eventManager): void {
		$eventManager->unsubscribe(SendMsgEvent::class, $this->captureEvent(...));
	}

	public function getOutput(): string {
		return $this->output;
	}

	private function captureEvent(SendMsgEvent $event): void {
		if ($event->channel !== $this->target) {
			return;
		}
		$this->output .= $event->message;
	}
}
