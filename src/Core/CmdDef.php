<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Types\{AccessLevel, Status};

/** This is the class that represents a command on the bot */
class CmdDef {
	/**
	 * @param string       $description   The description of the command
	 * @param AccessLevel  $accessLevel   The minimum required access
	 *                                    level to execute the command
	 * @param null|Status  $defaultStatus The default status (enabled or disabled)
	 * @param null|string  $help          A dedicated help page associated with
	 *                                    this command
	 * @param list<string> $handlers      A list of handlers that handle this command.
	 *                                    A handler is a string in the form
	 *                                    `<class name>.<function name>`
	 * @param null|string  $parentCommand If this is a sub-command, this is
	 *                                    the name of the parent command
	 */
	public function __construct(
		public string $description,
		public AccessLevel $accessLevel=AccessLevel::Mod,
		public ?Status $defaultStatus=null,
		public ?string $help=null,
		public array $handlers=[],
		public ?string $parentCommand=null,
	) {
	}
}
