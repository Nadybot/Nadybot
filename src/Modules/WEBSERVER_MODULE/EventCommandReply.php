<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\EventManager;

class EventCommandReply implements CommandReply {
	protected EventManager $eventManager;
	protected string $uuid;

	public function __construct(EventManager $eventManager, string $uuid) {
		$this->eventManager = $eventManager;
		$this->uuid = $uuid;
	}

	public function reply($msg): void {
		$event = new CommandReplyEvent();
		$event->msgs = (array)$msg;
		$event->uuid = $this->uuid;
		$event->type = "cmdreply";
		$this->eventManager->fireEvent($event);
	}
}
