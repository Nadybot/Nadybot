<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'cloak(raise)')]
class CloakRaiseEvent extends CloakEvent {
}
