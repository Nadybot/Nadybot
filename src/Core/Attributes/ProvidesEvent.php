<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE|Attribute::TARGET_CLASS)]
class ProvidesEvent {
	public function __construct(
		public string $event,
		public ?string $desc=null
	) {
	}
}
