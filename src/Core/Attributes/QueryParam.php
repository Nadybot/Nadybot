<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class QueryParam {
	public function __construct(
		public string $name,
		public string $desc,
		public bool $required=false,
		public string $in="query",
		public string $type="string"
	) {
	}
}
