<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{
	Attributes\DB,
	DBTable,
	Types\AccessLevel,
};

/** This is the database representation of help pages that are not coming from commands */
#[DB\Table(name: 'hlpcfg')]
class HlpCfg extends DBTable {
	/**
	 * @param string      $name         Name of the help topic
	 * @param string      $module       The module that defines this help topic
	 * @param string      $file         The file that contains the actual text to display
	 * @param string      $description  Short description what this help topic is about
	 * @param AccessLevel $access_level Access level required to see this help topic
	 * @param int         $verify       Internal state to track if a help topic
	 *                                  is still defined by the bot
	 */
	public function __construct(
		public string $name,
		public string $module,
		public string $file,
		public string $description,
		#[DB\ColName('admin')] public AccessLevel $access_level,
		public int $verify=0,
	) {
	}
}
