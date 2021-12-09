<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Instance {
	public function __construct(
		public ?string $value=null,
		public bool $overwrite=false
	) {
	}
}
