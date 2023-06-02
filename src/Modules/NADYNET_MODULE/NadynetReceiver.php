<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

use Nadybot\Core\Routing\{RoutableEvent};
use Nadybot\Core\{Attributes as NCA, LoggerWrapper, MessageReceiver};

class NadynetReceiver implements MessageReceiver {
	#[NCA\Inject]
	public NadynetController $nadynetController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public function getChannelName(): string {
		return "nadynet";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$this->logger->info("Message for Nadynet ({destination}) received", [
			"destination" => $destination,
		]);
		if (!$this->nadynetController->nadynetEnabled) {
			return false;
		}
		$data = $event->getData();
		if (!is_string($data)) {
			$this->logger->info("No data in message to Nadynet - dropping.");
			return false;
		}
		$prefix = $this->nadynetController->nadynetPrefix;
		if (!preg_match("/^" . preg_quote($prefix, "/") . "([a-zA-Z]+)/", $data, $matches)) {
			$this->logger->info("Data to Nadynet does not have the {prefix} prefix.", [
				"prefix" => $prefix,
			]);
			return false;
		}
		$channel = $this->guessChannel($matches[1]);
		if (!isset($channel)) {
			$this->logger->info("No Nadynet-channel found for {match} - dropping", [
				"match" => $matches[1],
			]);
			return false;
		}
		$message = ltrim(substr($data, strlen($matches[1])+1));

		return $this->nadynetController->handleIncoming($event, $channel, $message);
	}

	private function guessChannel(string $selector): ?string {
		$channels = [];
		foreach (NadynetController::CHANNELS as $channel) {
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
