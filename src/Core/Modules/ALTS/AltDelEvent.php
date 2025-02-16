<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'alt(del)')]
class AltDelEvent extends AltEvent {
}
