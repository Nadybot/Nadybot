<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Attributes\Event;

/** Dispatched every time a main deletes one of their alt characters */
#[Event(mask: 'alt(del)')]
class AltDelEvent extends AltEvent {
}
