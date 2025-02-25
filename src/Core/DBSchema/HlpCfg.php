<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Types\AccessLevel;
use Nadybot\Core\{Attributes as NCA, DBTable};

/** This is the database representation of help pages that are not coming from commands */
#[NCA\DB\Table(name: 'hlpcfg')]
class HlpCfg extends DBTable {
	/**
	 * @param string      $name        Name of the help topic
	 * @param string      $module      The module that defines this help topic
	 * @param string      $file        The file that contains the actual text to display
	 * @param string      $description Short description what this help topic is about
	 * @param AccessLevel $admin       Access level required to see this help topic
	 * @param int         $verify      Internal state to track if a help topic
	 *                                 is still defined by the bot
	 */
	public function __construct(
		public string $name,
		public string $module,
		public string $file,
		public string $description,
		public AccessLevel $admin,
		public int $verify=0,
	) {
	}
}
