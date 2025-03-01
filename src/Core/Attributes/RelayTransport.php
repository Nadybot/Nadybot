<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class provides a relay stack transport */
#[Attribute(Attribute::TARGET_CLASS)]
class RelayTransport extends ClassSpec {
}
