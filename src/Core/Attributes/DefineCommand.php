<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\Status;

#[Attribute(Attribute::TARGET_CLASS|Attribute::IS_REPEATABLE)]
class DefineCommand {
	/** @param null|string|list<string> $alias */
	public function __construct(
		public string $command,
		public string $description,
		public ?string $accessLevel=null,
		public ?string $help=null,
		public ?Status $defaultStatus=null,
		public null|string|array $alias=null
	) {
	}
}
