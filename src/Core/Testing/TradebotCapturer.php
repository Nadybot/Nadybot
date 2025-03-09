<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use AO\Utils;
use Nadybot\Core\EventManager;
use Nadybot\Modules\TRADEBOT_MODULE\TradebotMsgEvent;

/** An interface used to capture tradebot messages sent by the bot */
class TradebotCapturer implements CapturerInterface {
	private string $output = '';
	private readonly string $target;

	public function __construct(
		string $target
	) {
		$this->target = Utils::normalizeCharacter($target);
	}

	public function register(EventManager $eventManager): void {
		$eventManager->subscribe(TradebotMsgEvent::class, $this->captureEvent(...));
	}

	public function unregister(EventManager $eventManager): void {
		$eventManager->unsubscribe(TradebotMsgEvent::class, $this->captureEvent(...));
	}

	public function getOutput(): string {
		return $this->output;
	}

	private function captureEvent(TradebotMsgEvent $event): void {
		if ($event->tradebot !== $this->target) {
			return;
		}
		$this->output .= $event->message;
	}
}
