<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;

#[NCA\Instance]
class Websocket {
	#[NCA\Inject]
	public Timer $timer;

	public function createClient(): WebsocketClient {
		$client = new WebsocketClient();
		Registry::injectDependencies($client);
		$this->timer->callLater(0, [$client, 'connect']);
		return $client;
	}
}
