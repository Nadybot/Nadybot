<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use Nadybot\Core\DB;

class Database {
	/**
	 * @param DB\Type     $type     What type of database should be used? ('sqlite', 'postgresql', or 'mysql')
	 * @param string      $name     Name of the database
	 * @param string      $host     Hostname or sqlite file location
	 * @param null|string $username MySQL or PostgreSQL username
	 * @param null|string $password MySQL or PostgreSQL password
	 */
	public function __construct(
		public DB\Type $type=DB\Type::SQLite,
		public string $name='nadybot.db',
		public string $host='./data/',
		public ?string $username=null,
		public ?string $password=null,
	) {
	}
}
