<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class provides a relay protocol */
#[Attribute(Attribute::TARGET_CLASS)]
class RelayProtocol extends ClassSpec {
}
