<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This method handles the given command */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class HandlesCommand {
	public function __construct(public string $command) {
	}
}
