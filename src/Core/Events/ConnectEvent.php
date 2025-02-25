<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes as NCA;

/** Fired when the bot has successfully connected to the AO server */
#[NCA\Event(mask: 'connect')]
class ConnectEvent {
}
