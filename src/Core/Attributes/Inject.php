<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** Inject an instance of the property's class */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Inject {
	public function __construct(public ?string $instance=null) {
	}
}
