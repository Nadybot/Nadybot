<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @Instance
 */
class Websocket {
	/** @Inject */
	public Timer $timer;

	public function createClient(): WebsocketClient {
		$client = new WebsocketClient();
		Registry::injectDependencies($client);
		$this->timer->callLater(0, [$client, 'connect']);
		return $client;
	}
}
