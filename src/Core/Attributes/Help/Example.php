<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Help;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class Example {
	public function __construct(
		public string $command,
		public ?string $description=null,
	) {
	}
}
