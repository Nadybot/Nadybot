<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Quantity extends Regexp {
	public function __construct(
		?string $example=null,
	) {
		parent::__construct(value: '\d+)(?:[*x]?', example: $example);
	}
}
