<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Amp\Websocket\Client\Connection;
use Nadybot\Core\Event;

class DrillEvent extends Event {
	public Connection $client;
}
