<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\DB;

use Attribute;

/** Don't load or save this column to/from the database */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Ignore {
	public function __construct() {
	}
}
