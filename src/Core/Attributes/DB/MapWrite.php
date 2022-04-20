<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\DB;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class MapWrite extends MapRead {
}
