<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
	Attributes as NCA,
	MessageHub,
	Modules\CONSOLE\ConsoleCommandReply,
	Routing\RoutableEvent,
	Routing\Source,
};

class ConsoleChannel extends Base {
	#[NCA\Inject]
	public MessageHub $messageHub;

	protected ConsoleCommandReply $sendto;

	public function __construct(ConsoleCommandReply $sendto) {
		$this->sendto = $sendto;
	}

	public function getChannelName(): string {
		return Source::CONSOLE;
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$message = $this->getEventMessage($event, $this->messageHub);
		if (!isset($message)) {
			return false;
		}
		$this->sendto->replyOnly($message);
		return true;
	}
}
