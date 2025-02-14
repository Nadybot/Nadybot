<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class provides a relay stack element */
#[Attribute(Attribute::TARGET_CLASS)]
class RelayStackMember extends ClassSpec {
}
