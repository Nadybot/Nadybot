<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS|Attribute::IS_REPEATABLE)]
class DefineCommand {
	public function __construct(
		public string $command,
		public string $description,
		public ?string $channels=null,
		public ?string $accessLevel=null,
		public ?string $help=null,
		public ?int $defaultStatus=null,
		public ?string $alias=null
	) {
	}
}
