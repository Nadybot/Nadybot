<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\JSON;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Name {
	public function __construct(public string $name) {
	}
}
