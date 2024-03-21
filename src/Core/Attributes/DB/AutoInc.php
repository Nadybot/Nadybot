<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\DB;

use Attribute;

/**
 * This property is automatically filled and increased when inserting
 * the object into the database
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class AutoInc {
	public function __construct() {
	}
}
