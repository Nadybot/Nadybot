<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class RelayStackMember extends ClassSpec {
}
