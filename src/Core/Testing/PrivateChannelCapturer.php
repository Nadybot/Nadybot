<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use AO\Utils;
use Nadybot\Core\{
	EventManager,
	Events\SendPrivEvent,
};

/** An interface used to capture private channel messages sent by the bot */
class PrivateChannelCapturer implements CapturerInterface {
	/** The captured output string */
	private string $output = '';

	/** Name of the private channel we're capturing data from */
	private readonly string $target;

	public function __construct(
		string $target
	) {
		$this->target = Utils::normalizeCharacter($target);
	}

	/** {@inheritDoc} */
	public function register(EventManager $eventManager): void {
		$eventManager->subscribe(SendPrivEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function unregister(EventManager $eventManager): void {
		$eventManager->unsubscribe(SendPrivEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function getOutput(): string {
		return $this->output;
	}

	/** Capture the output whenever we send messages to our private channel */
	private function captureEvent(SendPrivEvent $event): void {
		if ($event->channel !== $this->target) {
			return;
		}
		$this->output .= $event->message;
	}
}
