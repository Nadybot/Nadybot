<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Attributes\Event;

/** Dispatched every time a new main is set */
#[Event(mask: 'alt(newmain)')]
class AltNewMainEvent extends AltEvent {
}
