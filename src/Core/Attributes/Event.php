<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\Status;

#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class Event {
	/** @param string|list<string> $name */
	public function __construct(
		public string|array $name,
		public string $description,
		public ?string $help=null,
		public ?Status $defaultStatus=null,
	) {
	}
}
