<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** This string should only be as long as necessary */
#[Attribute(Attribute::TARGET_PARAMETER)]
class NonGreedy extends Regexp {
	public function __construct(
		?string $example=null,
	) {
		parent::__construct(value: '.+?', example: $example);
	}
}
