<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;

/** This string has to be a theoretically valid filename */
#[Attribute(Attribute::TARGET_PARAMETER)]
class FilenameStr extends Regexp {
	public function __construct(
		?string $example=null,
	) {
		parent::__construct(value: '[a-zA-Z0-9_.-]+', example: $example);
	}
}
