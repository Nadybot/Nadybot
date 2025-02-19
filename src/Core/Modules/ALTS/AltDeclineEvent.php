<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Attributes\Event;

/** Dispatched every time a main declines to add a character as their alt */
#[Event(mask: 'alt(decline)')]
class AltDeclineEvent extends AltEvent {
}
