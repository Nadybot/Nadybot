<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\DB;

use Attribute;

/**
 * Before writing a value to the database,
 * modify it with the given function
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MapWrite extends MapRead {
}
