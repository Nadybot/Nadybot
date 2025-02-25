<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\DB;

use Attribute;

/** This property has a different column name in the database */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ColName {
	public function __construct(
		public readonly string $col,
	) {
	}
}
