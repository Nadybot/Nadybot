<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;
use Nadybot\Core\Types\AccessLevel;

/** This represents a help-topic search result */
class HelpTopic extends DBRow {
	/**
	 * @param AccessLevel $access_level The access level required to execute the
	 *                                  command, or change the setting. If you are not
	 *                                  allowed to execute the command in any of the
	 *                                  permission sets, or aren't allowed to change
	 *                                  a setting, you won't be able to see its help.
	 * @param string      $module       Name of the module that defines the command
	 * @param string      $name         Name of the help topic/command
	 * @param string      $description  Description to display
	 * @param null|int    $sort         Sort order
	 * @param null|string $file         The file that defines the help/command
	 */
	public function __construct(
		public AccessLevel $access_level,
		public string $module,
		public string $name,
		public string $description,
		public ?int $sort=null,
		public ?string $file=null,
	) {
	}
}
