<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE;

use function Safe\preg_match;
use Nadybot\Core\Routing\{RoutableEvent};
use Nadybot\Core\{Attributes as NCA, MessageReceiver};

use Psr\Log\LoggerInterface;

class HighnetReceiver implements MessageReceiver {
	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HighnetController $highnetController;

	public function getChannelName(): string {
		return "highnet";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$this->logger->info("Message for Highnet ({destination}) received.", [
			"destination" => $destination,
		]);
		if (!$this->highnetController->highnetEnabled) {
			return false;
		}
		$data = $event->getData();
		if (!is_string($data)) {
			$this->logger->info("No data in message to Highnet - dropping.");
			return false;
		}
		$prefix = $this->highnetController->highnetPrefix;
		if (!preg_match("/^" . preg_quote($prefix, "/") . "([a-zA-Z]+)/", $data, $matches)) {
			$this->logger->info("Data to Highnet does not have the {prefix} prefix.", [
				"prefix" => $prefix,
			]);
			return false;
		}
		$channel = $this->guessChannel($matches[1]);
		if (!isset($channel)) {
			$this->logger->info("No Highnet-channel found for {match} - dropping", [
				"match" => $matches[1],
			]);
			return false;
		}
		$message = ltrim(substr($data, strlen($matches[1])+1));
		if (!strlen($message)) {
			$this->logger->info("Not routing an empty message to Highnet({channel})", [
				"channel" => $channel,
			]);
			return false;
		}

		return $this->highnetController->handleIncoming($event, $channel, $message);
	}

	private function guessChannel(string $selector): ?string {
		$channels = [];
		foreach (HighnetController::CHANNELS as $channel) {
			if (strncasecmp($channel, $selector, strlen($selector)) === 0) {
				$channels []= $channel;
			}
		}
		if (count($channels) !== 1) {
			return null;
		}
		return $channels[0];
	}
}
