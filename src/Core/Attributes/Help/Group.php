<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Help;

use Attribute;

/** Display this command's help together with other commands in the same Group */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class Group {
	public function __construct(
		public string $group
	) {
	}
}
