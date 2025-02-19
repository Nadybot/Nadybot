<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Attributes\Event;

/**
 * Dispatched every time a main character validates one of their alts.
 * This is not triggered when no validation is needed
 */
#[Event(mask: 'alt(validate)')]
class AltValidateEvent extends AltEvent {
}
