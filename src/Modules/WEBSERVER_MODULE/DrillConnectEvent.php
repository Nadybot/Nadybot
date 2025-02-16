<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'drill(connect)')]
class DrillConnectEvent extends DrillEvent {
}
