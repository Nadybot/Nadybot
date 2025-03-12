<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use AO\Utils;
use Nadybot\Core\{
	EventManager,
	Events\SendMsgEvent,
};

/** An interface used to capture tell messages sent by the bot */
class TellCapturer implements CapturerInterface {
	/** The captured output string */
	private string $output = '';

	/** Name of the character we want to capture messages to */
	private readonly string $target;

	public function __construct(
		string $target
	) {
		$this->target = Utils::normalizeCharacter($target);
	}

	/** {@inheritDoc} */
	public function register(EventManager $eventManager): void {
		$eventManager->subscribe(SendMsgEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function unregister(EventManager $eventManager): void {
		$eventManager->unsubscribe(SendMsgEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function getOutput(): string {
		return $this->output;
	}

	/** Capture the output whenever we send a tell message to someone */
	private function captureEvent(SendMsgEvent $event): void {
		if ($event->channel !== $this->target) {
			return;
		}
		$this->output .= $event->message;
	}
}
