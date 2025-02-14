<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class has parameters to configure it */
#[Attribute(Attribute::TARGET_CLASS|Attribute::IS_REPEATABLE)]
class Param {
	public function __construct(
		public string $name,
		public string $type,
		public bool $required,
		public string $description='',
	) {
	}
}
