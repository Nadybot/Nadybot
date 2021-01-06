<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\EventManager;
use Nadybot\Core\Nadybot;
use Nadybot\Core\SettingManager;

class EventCommandReply implements CommandReply {
	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Nadybot $chatBot;

	protected string $uuid;

	public function __construct(string $uuid) {
		$this->uuid = $uuid;
	}

	public function reply($msg): void {
		$event = new CommandReplyEvent();
		$event->msgs = AOMsg::fromMsgs((array)$msg);
		$event->uuid = $this->uuid;
		$event->type = "cmdreply";
		$this->eventManager->fireEvent($event);
	}
}
