<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\MessageEmitter;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;

class WebUIChannel implements CommandReply, MessageEmitter {
	public MessageHub $messageHub;

	public function __construct(MessageHub $hub) {
		$this->messageHub = $hub;
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(webui)";
	}

	public function reply($msg): void {
		foreach ((array)$msg as $packet) {
			$r = new RoutableMessage($packet);
			$r->appendPath(new Source(
				Source::SYSTEM,
				"webui"
			));
			$this->messageHub->handle($r);
		}
	}
}
