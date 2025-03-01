<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class can be used to modify routed events/messages */
#[Attribute(Attribute::TARGET_CLASS)]
class EventModifier extends ClassSpec {
}
