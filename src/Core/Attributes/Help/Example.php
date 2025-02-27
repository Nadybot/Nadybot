<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Help;

use Attribute;

/** Add an example to a command invocation */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class Example {
	/**
	 * @param string      $command     How the command is called, including <symbol>
	 * @param null|string $description What is this command invocation doing?
	 */
	public function __construct(
		public string $command,
		public ?string $description=null,
	) {
	}
}
