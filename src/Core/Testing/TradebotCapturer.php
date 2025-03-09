<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use AO\Utils;
use Nadybot\Core\EventManager;
use Nadybot\Modules\TRADEBOT_MODULE\TradebotMsgEvent;

/** An interface used to capture tradebot messages sent by the bot */
class TradebotCapturer implements CapturerInterface {
	/** The captured output string */
	private string $output = '';

	/** The nae of the radebot we capture messages from */
	private readonly string $tradebot;

	public function __construct(
		string $tradebot
	) {
		$this->tradebot = Utils::normalizeCharacter($tradebot);
	}

	/** {@inheritDoc} */
	public function register(EventManager $eventManager): void {
		$eventManager->subscribe(TradebotMsgEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function unregister(EventManager $eventManager): void {
		$eventManager->unsubscribe(TradebotMsgEvent::class, $this->captureEvent(...));
	}

	/** {@inheritDoc} */
	public function getOutput(): string {
		return $this->output;
	}

	/** Capture tadebot messages we render and send */
	private function captureEvent(TradebotMsgEvent $event): void {
		if ($event->tradebot !== $this->tradebot) {
			return;
		}
		$this->output .= $event->message;
	}
}
