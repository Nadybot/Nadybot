<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

use Nadybot\Core\Routing\{RoutableEvent};
use Nadybot\Core\{Attributes as NCA, MessageReceiver};

class NadynetReceiver implements MessageReceiver {
	#[NCA\Inject]
	public NadynetController $nadynetController;

	public function getChannelName(): string {
		return "nadynet";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if (!$this->nadynetController->nadynetEnabled) {
			return false;
		}
		$data = $event->getData();
		if (!is_string($data)) {
			return false;
		}
		$prefix = $this->nadynetController->nadynetPrefix;
		if (!preg_match("/^" . preg_quote($prefix, "/") . "([a-zA-Z]+)/", $data, $matches)) {
			return false;
		}
		$channel = $this->guessChannel($matches[1]);
		if (!isset($channel)) {
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
