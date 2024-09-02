<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\{RoutableMessage, Source};
use Nadybot\Core\Types\{CommandReply, MessageEmitter};

class WebUIChannel implements CommandReply, MessageEmitter {
	public function __construct(
		private MessageHub $messageHub
	) {
	}

	public function getChannelName(): string {
		return Source::SYSTEM . '(webui)';
	}

	/** @inheritDoc */
	public function reply(string|array $msg): void {
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
