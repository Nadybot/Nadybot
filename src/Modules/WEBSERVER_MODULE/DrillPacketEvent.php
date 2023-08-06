<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Amp\Websocket\Client\Connection;
use Nadybot\Core\Event;
use Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

class DrillPacketEvent extends Event {
	public Connection $client;
	public Packet\Base $packet;
}
