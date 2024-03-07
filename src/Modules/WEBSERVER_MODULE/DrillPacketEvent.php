<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Amp\Websocket\Client\WebsocketConnection;
use Nadybot\Core\Event;
use Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

class DrillPacketEvent extends Event {
	public WebsocketConnection $client;
	public Packet\Base $packet;
}
