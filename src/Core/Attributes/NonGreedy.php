<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class NonGreedy extends Regexp {
	public function __construct(
		?string $example=null,
	) {
		parent::__construct(value: '.+?', example: $example);
	}
}
