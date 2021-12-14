<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class Event {
	public function __construct(
		/** @var string|string[] */
		public string|array $name,
		public string $description,
		public ?string $help=null,
		public ?int $defaultStatus=null,
	) {
	}
}
