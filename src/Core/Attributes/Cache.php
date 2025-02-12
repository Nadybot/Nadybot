<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Cache {
	public function __construct(public ?string $prefix=null) {
	}
}
