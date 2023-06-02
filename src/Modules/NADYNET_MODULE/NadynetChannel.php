<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

use Nadybot\Core\Routing\{RoutableEvent};
use Nadybot\Core\{Attributes as NCA, MessageEmitter, MessageReceiver};

class NadynetChannel implements MessageEmitter, MessageReceiver {
	#[NCA\Inject]
	public NadynetController $nadynetController;

	public function __construct(
		private string $channel
	) {
	}

	public function getChannelName(): string {
		return "nadynet({$this->channel})";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$data = $event->getData();
		if (!is_string($data)) {
			return false;
		}
		return $this->nadynetController->handleIncoming($event, $destination, $data);
	}
}
