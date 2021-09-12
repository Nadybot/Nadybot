<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;

class WebUIChannel implements CommandReply {
	public MessageHub $messageHub;

	public function __construct(MessageHub $hub) {
		$this->messageHub = $hub;
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
