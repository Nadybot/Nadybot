<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Http;

use Attribute;

/** Describe the result of this API call */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class ApiResult {
	public function __construct(
		public int $code,
		public string $desc,
		public ?string $class=null
	) {
	}
}
