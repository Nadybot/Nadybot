<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Attributes\Event;

/**
 * Dispatched every time someone adds a new alt to a main,
 * or only requests a new alt to be added
 */
#[Event(mask: 'alt(add)')]
class AltAddEvent extends AltEvent {
}
