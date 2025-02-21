<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Http;

use Attribute;
use Nadybot\Core\Types\AccessLevel as TypesAccessLevel;

/** The access level required for this API call */
#[Attribute(Attribute::TARGET_METHOD|Attribute::TARGET_PROPERTY)]
class AccessLevel {
	public function __construct(public TypesAccessLevel $value) {
	}
}
