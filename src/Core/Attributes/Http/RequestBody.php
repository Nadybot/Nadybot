<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Http;

use Attribute;

/** This method requires the request to send data in a given format */
#[Attribute(Attribute::TARGET_METHOD)]
class RequestBody {
	public function __construct(
		public string $class,
		public string $desc,
		public bool $required
	) {
	}
}
