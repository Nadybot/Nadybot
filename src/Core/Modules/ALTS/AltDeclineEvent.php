<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'alt(decline)')]
class AltDeclineEvent extends AltEvent {
}
