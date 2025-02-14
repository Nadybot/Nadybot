<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use Nadybot\Core\Attributes\Hydrator\Confidential;
use Nadybot\Core\DB\DBType;

/** The database configuration */
class Database {
	/**
	 * @param DBType      $type     What type of database should be used? ('sqlite', 'postgresql', or 'mysql')
	 * @param string      $name     Name of the database
	 * @param string      $host     Hostname or sqlite file location
	 * @param null|string $username MySQL or PostgreSQL username
	 * @param null|string $password MySQL or PostgreSQL password
	 */
	public function __construct(
		public DBType $type=DBType::SQLite,
		public string $name='nadybot.db',
		public string $host='./data/',
		#[Confidential] public ?string $username=null,
		#[Confidential] public ?string $password=null,
	) {
	}
}
