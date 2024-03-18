<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CommandReply,
	EventManager,
};

class EventCommandReply implements CommandReply {
	protected string $uuid;
	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private WebChatConverter $webChatConverter;

	public function __construct(string $uuid) {
		$this->uuid = $uuid;
	}

	/** @param string|string[] $msg */
	public function reply(string|array $msg): void {
		$event = new CommandReplyEvent(
			msgs: $this->webChatConverter->convertMessages((array)$msg),
			uuid: $this->uuid,
		);
		$this->eventManager->fireEvent($event);
	}
}
