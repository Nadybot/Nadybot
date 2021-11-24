<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\EventManager;

class EventCommandReply implements CommandReply {
	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public WebChatConverter $webChatConverter;

	protected string $uuid;

	public function __construct(string $uuid) {
		$this->uuid = $uuid;
	}

	/** @param string|string[] $msg */
	public function reply($msg): void {
		$event = new CommandReplyEvent();
		$event->msgs = $this->webChatConverter->convertMessages((array)$msg);
		$event->uuid = $this->uuid;
		$event->type = "cmdreply";
		$this->eventManager->fireEvent($event);
	}
}
