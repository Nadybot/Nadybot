<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ApiResult {
	public function __construct(
		public int $code,
		public string $class,
		public string $desc
	) {
	}
}
