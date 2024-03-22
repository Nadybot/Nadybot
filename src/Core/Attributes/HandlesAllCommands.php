<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class HandlesAllCommands extends HandlesCommand {
	public string $command = '';

	public function __construct() {
	}
}
