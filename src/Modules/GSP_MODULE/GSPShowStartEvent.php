<?php declare(strict_types=1);

namespace Nadybot\Modules\GSP_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'gsp(show_start)')]
class GSPShowStartEvent extends GSPEvent {
}
