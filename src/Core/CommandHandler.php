<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Types\AccessLevel;

/** A command handler represents all possible handlers for a command */
class CommandHandler {
	/**
	 * A list of handlers in the format `<class name>.<function name>`
	 *
	 * @var list<string>
	 */
	public array $files;

	/**
	 * @param AccessLevel $access_level The minimum access level required to run the command
	 * @param string      ...$fileName  A list of handlers in the format `<class name>.<function name>`
	 */
	public function __construct(
		public AccessLevel $access_level,
		string ...$fileName
	) {
		$this->files = array_values($fileName);
	}

	/**
	 * Add one or more handlers to the command handler list
	 *
	 * @param string ...$file A list of handlers in the format `<class name>.<function name>`
	 */
	public function addFile(string ...$file): self {
		$this->files = array_values(array_merge($this->files, $file));
		return $this;
	}
}
