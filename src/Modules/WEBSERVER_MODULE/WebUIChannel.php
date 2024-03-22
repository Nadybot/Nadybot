<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Routing\{RoutableMessage, Source};
use Nadybot\Core\{CommandReply, MessageEmitter, MessageHub};

class WebUIChannel implements CommandReply, MessageEmitter {
	public MessageHub $messageHub;

	public function __construct(MessageHub $hub) {
		$this->messageHub = $hub;
	}

	public function getChannelName(): string {
		return Source::SYSTEM . '(webui)';
	}

	public function reply($msg): void {
		foreach ((array)$msg as $packet) {
			$r = new RoutableMessage($packet);
			$r->appendPath(new Source(
				Source::SYSTEM,
				'webui'
			));
			$this->messageHub->handle($r);
		}
	}
}
