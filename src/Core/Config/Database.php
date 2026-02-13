<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use Nadybot\Core\Attributes\Hydrator\Confidential;
use Nadybot\Core\DB\DBType;

/** The database configuration */
class Database {
	/**
	 * @param DBType      $type             What type of database should be used? ('sqlite', 'postgresql', or 'mysql')
	 * @param string      $name             Name of the database, or the database file for SQLite
	 * @param string      $host             Hostname or SQLite file path
	 * @param null|string $username         MySQL or PostgreSQL username
	 * @param null|string $password         MySQL or PostgreSQL password
	 * @param bool        $migrationBackups Whether to create a backup of the database before applying migrations.
	 *                                      Currently only available for SQLite databases
	 */
	public function __construct(
		public DBType $type=DBType::SQLite,
		public string $name='nadybot.db',
		public string $host='./data/',
		#[Confidential] public ?string $username=null,
		#[Confidential] public ?string $password=null,
		public bool $migrationBackups=true,
	) {
	}
}
