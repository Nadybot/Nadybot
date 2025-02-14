<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** This string is a single word containing at least 1 non-digit character */
#[Attribute(Attribute::TARGET_PARAMETER)]
class NonNumberWord extends Regexp {
	public function __construct(
		?string $example=null,
	) {
		parent::__construct(value: '[^ ]*[^\d ][^ ]*', example: $example);
	}
}
