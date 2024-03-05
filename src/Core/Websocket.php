<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Revolt\EventLoop;

#[NCA\Instance]
class Websocket {
	public function createClient(): WebsocketClient {
		$client = new WebsocketClient();
		Registry::injectDependencies($client);
		EventLoop::defer(function (string $ignore) use ($client): void {
			$client->connect();
		});
		return $client;
	}
}
