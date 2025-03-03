<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class has tests that can be used to test the bot */
#[Attribute(Attribute::TARGET_CLASS)]
class HasTests {
	public function __construct(
		public string $dir='tests',
		public ?string $module=null,
	) {
	}
}
