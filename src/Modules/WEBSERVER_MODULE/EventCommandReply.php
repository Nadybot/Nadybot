<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	Blob,
	EventManager,
	Types\CommandReply,
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

	/** @inheritDoc */
	public function reply(string|array $msg): void {
		$msg = array_map(
			static fn (string $text): string => Blob::create($text)->getText(),
			(array)$msg
		);
		$event = new CommandReplyEvent(
			msgs: $this->webChatConverter->convertMessages($msg),
			uuid: $this->uuid,
		);
		$this->eventManager->dispatch($event);
	}
}
