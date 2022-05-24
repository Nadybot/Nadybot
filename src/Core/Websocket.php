<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Loop;
use Nadybot\Core\Attributes as NCA;

#[NCA\Instance]
class Websocket {
	public function createClient(): WebsocketClient {
		$client = new WebsocketClient();
		Registry::injectDependencies($client);
		Loop::defer(fn() => $client->connect());
		return $client;
	}
}
