<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE;

use Nadybot\Core\{Attributes as NCA, Blob, Types\MessageEmitter, Types\MessageReceiver};
use Nadybot\Core\Routing\RoutableEvent;

class HighnetChannel implements MessageEmitter, MessageReceiver {
	#[NCA\Inject]
	private HighnetController $highnetController;

	public function __construct(
		private string $channel
	) {
	}

	public function getChannelName(): string {
		return "highnet({$this->channel})";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$data = $event->getData();
		if (!is_string($data)) {
			return false;
		}
		$data = Blob::create($data)->getText();
		return $this->highnetController->handleIncoming($event, $destination, $data);
	}
}
