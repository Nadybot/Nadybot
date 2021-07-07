<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use DateTime;
use PDO;
use PDOException;
use PDOStatement;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Nadybot\Core\CSV\Reader;
use Nadybot\Core\DBSchema\Migration;
use Throwable;

/**
 * @Instance
 */
class DB {

	public const SQLITE_MIN_VERSION = "3.23.0";

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Util $util;

	/**
	 * The database type: mysql/sqlite
	 */
	private string $type;

	/**
	 * The PDO object to talk to the database
	 */
	private PDO $sql;

	/**
	 * The low-level Capsule manager object
	 */
	private Capsule $capsule;

	/**
	 * The name of the bot
	 */
	private string $botname;

	/**
	 * The dimension
	 */
	private int $dim;

	/**
	 * The database name
	 */
	protected string $dbName;

	private string $guild;
	private string $lastQuery;
	private array $meta = [];
	private array $metaTypes = [];

	private LoggerWrapper $logger;

	protected array $sqlReplacements = [];
	protected array $sqlRegexpReplacements = [];
	protected array $sqlCreateReplacements = [];

	protected array $tableNames = [];

	private Closure $reconnect;

	public const MYSQL = 'mysql';
	public const SQLITE = 'sqlite';
	public const POSTGRESQL = 'postgresql';
	public const MSSQL = 'mssql';

	public function __construct() {
		$this->logger = new LoggerWrapper('SQL');
	}

	/** Get the lowercased name of the bot */
	public function getBotname(): string {
		return $this->botname;
	}

	/** Get the correct name of the bot */
	public function getMyname(): string {
		return ucfirst($this->botname);
	}

	/** Get the correct guild name of the bot */
	public function getMyguild(): string {
		return ucfirst($this->guild);
	}

	/** Get the dimension id of the bot */
	public function getDim(): int {
		return $this->dim;
	}

	/**
	 * Connect to the database
	 *
	 * @throws Exception for unsupported database types
	 */
	public function connect(string $type, string $dbName, ?string $host=null, ?string $user=null, ?string $pass=null): void {
		$this->reconnect = function() use ($type, $dbName, $host, $user, $pass): void {
			$this->connect($type, $dbName, $host, $user, $pass);
		};
		global $vars;
		$errorShown = isset($this->sql);
		unset($this->sql);
		$this->dbName = $dbName;
		$this->type = strtolower($type);
		$this->botname = strtolower($vars["name"]);
		$this->dim = $vars["dimension"];
		$this->guild = str_replace("'", "''", $vars["my_guild"]);
		$this->capsule = new Capsule();

		if ($this->type === self::MYSQL) {
			do {
				try {
					$this->capsule->addConnection([
						'driver' => 'mysql',
						'host' => $host,
						'database' => $dbName,
						'username' => $user,
						'password' => $pass,
						'charset' => 'utf8',
						'collation' => 'utf8_unicode_ci',
						'prefix' => ''
					]);
					$this->sql = $this->capsule->getConnection()->getPdo();
				} catch (Throwable $e) {
					if (!$errorShown) {
						$this->logger->log(
							"ERROR",
							"Cannot connect to the MySQL db at {$host}: ".
							trim($e->errorInfo[2])
						);
						$this->logger->log(
							"INFO",
							"Will keep retrying until the db is back up again"
						);
						$errorShown = true;
					}
					sleep(1);
				}
			} while (!isset($this->sql));
			if ($errorShown) {
				$this->logger->log("INFO", "Database connection re-established");
			}
			$this->sql->exec("SET sql_mode = 'TRADITIONAL,NO_BACKSLASH_ESCAPES'");
			$this->sql->exec("SET time_zone = '+00:00'");
			$mysqlVersion = $this->sql->getAttribute(PDO::ATTR_SERVER_VERSION);

			// MariaDB 10.0.12 made aria storage stable which is read-optimized
			if (preg_match("/MariaDB-\d+:([0-9.]+)/", $mysqlVersion, $matches)) {
				if (version_compare($matches[1], "10.0.12") >= 0) {
					$this->sql->exec("SET default_storage_engine = aria");
				}
			}
			$this->sqlCreateReplacements[" AUTOINCREMENT"] = " AUTO_INCREMENT";
		} elseif ($this->type === self::SQLITE) {
			if ($host === null || $host === "" || $host === "localhost") {
				$dbName = "./data/$dbName";
			} else {
				$dbName = "$host/$dbName";
			}
			if (!@file_exists($dbName)) {
				if (!touch($dbName)) {
					$this->logger->log(
						'ERROR',
						"Unable to create the dababase \"{$dbName}\". Check that the directory ".
						"exists and is writable by the current user."
					);
					exit(10);
				}
			}
			$this->capsule->addConnection([
				'driver' => 'sqlite',
				'database' => $dbName,
				'prefix' => ''
			]);
			$this->sql = $this->capsule->getConnection()->getPdo();

			$sqliteVersion = $this->sql->getAttribute(PDO::ATTR_SERVER_VERSION);
			$this->sqlCreateReplacements[" AUTO_INCREMENT"] = " AUTOINCREMENT";
			$this->sqlCreateReplacements[" INT "] = " INTEGER ";
			$this->sqlCreateReplacements[" INT,"] = " INTEGER,";
			if (version_compare($sqliteVersion, static::SQLITE_MIN_VERSION, "<")) {
				$this->sqlCreateReplacements[" DEFAULT TRUE"] = " DEFAULT 1";
				$this->sqlCreateReplacements[" DEFAULT FALSE"] = " DEFAULT 0";
				$this->sqlReplacements[" IS TRUE"] = "=1";
				$this->sqlReplacements[" IS NOT TRUE"] = "!=1";
				$this->sqlReplacements[" IS FALSE"] = "=0";
				$this->sqlReplacements[" IS NOT FALSE"] = "!=0";
				$this->sqlRegexpReplacements["/(?<=[( ,])true(?=[) ,])/i"] = "1";
				$this->sqlRegexpReplacements["/(?<=[( ,])false(?=[) ,])/i"] = "0";
			}
		} elseif ($this->type === self::POSTGRESQL) {
			do {
				try {
					$this->capsule->addConnection([
						'driver' => 'pgsql',
						'host' => $host,
						'database' => $dbName,
						'username' => $user,
						'password' => $pass,
						'charset' => 'utf8',
						'collation' => 'utf8_unicode_ci',
						'prefix' => ''
					]);
					$this->sql = $this->capsule->getConnection()->getPdo();
				} catch (Throwable $e) {
					if (!$errorShown) {
						$this->logger->log(
							"ERROR",
							"Cannot connect to the PostgreSQL db at {$host}: ".
							trim($e->errorInfo[2])
						);
						$this->logger->log(
							"INFO",
							"Will keep retrying until the db is back up again"
						);
						$errorShown = true;
					}
					sleep(1);
				}
			} while (!isset($this->sql));
			if ($errorShown) {
				$this->logger->log("INFO", "Database connection re-established");
			}
		} elseif ($this->type === self::MSSQL) {
			do {
				try {
					$this->capsule->addConnection([
						'driver' => 'sqlsrv',
						'host' => $host,
						'database' => $dbName,
						'username' => $user,
						'password' => $pass,
						'charset' => 'utf8',
						'collation' => 'utf8_unicode_ci',
						'prefix' => ''
					]);
					$this->sql = $this->capsule->getConnection()->getPdo();
				} catch (Throwable $e) {
					if (!$errorShown) {
						$this->logger->log(
							"ERROR",
							"Cannot connect to the MSSQL db at {$host}: ".
							trim($e->errorInfo[2])
						);
						$this->logger->log(
							"INFO",
							"Will keep retrying until the db is back up again"
						);
						$errorShown = true;
					}
					sleep(1);
				}
			} while (!isset($this->sql));
			if ($errorShown) {
				$this->logger->log("INFO", "Database connection re-established");
			}
		} else {
			throw new Exception("Invalid database type: '$type'.  Expecting '" . self::MYSQL . "' or '" . self::SQLITE . "'.");
		}
		$this->capsule->setAsGlobal();
		$this->capsule->setFetchMode(PDO::FETCH_CLASS, DBRow::class);
	}

	/**
	 * Get the configured database type
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * Execute an SQL statement and return the first row as object or null if no results
	 * @deprecated Will be removed in Nadybot 6.0
	 */
	public function queryRow(string $sql): ?DBRow {
		$result = $this->query(...func_get_args());

		if (count($result) === 0) {
			return null;
		}
		return $result[0];
	}

	/**
	 * Populate and return an array of type changers for a query
	 *
	 * @return Closure[]
	 */
	protected function getTypeChanger(PDOStatement $ps, object $row): array {
		$metaKey = md5($ps->queryString);
		$numColumns = $ps->columnCount();
		if (isset($this->meta[$metaKey])) {
			return $this->meta[$metaKey];
		}
		$this->meta[$metaKey] = [];
		for ($col=0; $col < $numColumns; $col++) {
			$colMeta = $ps->getColumnMeta($col);
			$type = $this->guessVarTypeFromColMeta($colMeta, $colMeta["name"]);
			$refProp = new ReflectionProperty($row, $colMeta["name"]);
			$refProp->setAccessible(true);
			if ($type === "bool") {
				$this->meta[$metaKey] []= function(object $row) use ($refProp) {
					$stringValue = $refProp->getValue($row);
					if ($stringValue !== null) {
						$refProp->setValue($row, (bool)$stringValue);
					}
				};
			} elseif ($type === "int") {
				$this->meta[$metaKey] []= function(object $row) use ($refProp) {
					$stringValue = $refProp->getValue($row);
					if ($stringValue !== null) {
						$refProp->setValue($row, (int)$stringValue);
					}
				};
			}
		}
		return $this->meta[$metaKey];
	}

	/**
	 * Execute an SQL statement and return all rows as an array of objects
	 *
	 * @deprecated Will be removed in Nadybot 6.0
	 * @return \Nadybot\Core\DBRow[] All returned rows
	 */
	public function query(string $sql): array {
		$sql = $this->formatSql($sql);

		$args = $this->getParameters(func_get_args());

		$sql = $this->applySQLCompatFixes($sql);
		$ps = $this->executeQuery($sql, $args);
		$ps->setFetchMode(PDO::FETCH_CLASS, DBRow::class);
		$result = [];
		while ($row = $ps->fetch(PDO::FETCH_CLASS)) {
			$typeChangers = $this->getTypeChanger($ps, $row);
			foreach ($typeChangers as $changer) {
				$changer($row);
			}
			$result []= $row;
		}
		return $result;
	}

	/**
	 * Execute an SQL statement and return all rows as an array of objects of the given class
	 * @deprecated Will be removed in Nadybot 6.0
	 */
	public function fetchAll(string $className, string $sql, ...$args): array {
		$sql = $this->formatSql($sql);

		$sql = $this->applySQLCompatFixes($sql);
		$ps = $this->executeQuery($sql, $args);
		return $ps->fetchAll(
			PDO::FETCH_FUNC,
			function (...$values) use ($ps, $className) {
				return $this->convertToClass($ps, $className, $values);
			}
		);
	}

	/**
	 * Execute an SQL statement and return the first row as an objects of the given class
	 * @deprecated Will be removed in Nadybot 6.0
	 */
	public function fetch(string $className, string $sql, ...$args) {
		return $this->fetchAll(...func_get_args())[0] ?? null;
	}

	protected function guessVarTypeFromReflection(ReflectionClass $refClass, string $colName): ?string {
		if (!$refClass->hasProperty($colName)) {
			return null;
		}
		$refProp = $refClass->getProperty($colName);
		$refType = $refProp->getType();
		if ($refType instanceof ReflectionNamedType) {
			return $refType->getName();
		}
		return null;
	}

	protected function guessVarTypeFromColMeta(array $colMeta, string $colName): ?string {
		$type = strtolower($colMeta["native_type"]);
		$declType = strtolower($colMeta["sqlite:decl_type"] ?? "");
		if (!in_array($type, ["integer", "tiny", "long", "newdecimal"])
			&& !in_array($declType, ["int", "boolean", "tinyint(1)"])) {
			return null;
		}
		if (
			$type === 'tiny'
			|| (in_array($declType, ['boolean', 'tinyint(1)']))
		) {
			return "bool";
		} else {
			return "int";
		}
	}

	public function convertToClass(PDOStatement $ps, string $className, array $values) {
		$row = new $className();
		$refClass = new ReflectionClass($row);
		$metaKey = md5($ps->queryString);
		$numColumns = $ps->columnCount();
		if (!isset($this->metaTypes[$metaKey])) {
			$this->metaTypes[$metaKey] = [];
			for ($col=0; $col < $numColumns; $col++) {
				$this->metaTypes[$metaKey] []= $ps->getColumnMeta($col);
			}
		}
		$meta = $this->metaTypes[$metaKey];
		for ($col=0; $col < $numColumns; $col++) {
			$colMeta = $meta[$col];
			$colName = $colMeta['name'];
			if ($values[$col] === null) {
				try {
					$refProp = $refClass->getProperty($colName);
					if ($refProp->getType()->allowsNull()) {
						$row->{$colName} = $values[$col];
					}
				} catch (ReflectionException $e) {
					$row->{$colName} = null;
				}
				continue;
			}
			try {
				$type = $this->guessVarTypeFromReflection($refClass, $colName)
					?? $this->guessVarTypeFromColMeta($colMeta, $colName);
				if ($type === "bool") {
					$row->{$colName} = (bool)$values[$col];
				} elseif ($type === "int") {
					$row->{$colName} = (int)$values[$col];
				} elseif ($type === "float") {
					$row->{$colName} = (float)$values[$col];
				} elseif ($type === DateTime::class) {
					$row->{$colName} = (new DateTime())->setTimestamp((int)$values[$col]);
				} else {
					$row->{$colName} = $values[$col];
				}
			} catch (Throwable $e) {
				$this->logger->log(
					'ERROR',
					$e->getMessage() . ' in file ' . $e->getFile() . ':' . $e->getLine(),
					$e
				);
				throw $e;
			}
		}
		return $row;
	}

	/**
	 * Change the SQL to work in a variety of MySQL/SQLite versions
	 */
	public function applySQLCompatFixes(string $sql): string {
		if (!empty($this->sqlReplacements)) {
			$search = array_keys($this->sqlReplacements);
			$replace = array_values($this->sqlReplacements);
			$sql = str_ireplace($search, $replace, $sql);
		}
		foreach ($this->sqlRegexpReplacements as $search => $replace) {
			$sql = preg_replace($search, $replace, $sql);
		}
		return $sql;
	}

	/**
	 * Execute a query and return the number of affected rows
	 * @deprecated Will be removed in Nadybot 6.0
	 */
	public function exec(string $sql): int {
		$sql = $this->formatSql($sql);

		if (!empty($this->sqlCreateReplacements) && substr_compare($sql, "create ", 0, 7, true) === 0) {
			$search = array_keys($this->sqlCreateReplacements);
			$replace = array_values($this->sqlCreateReplacements);
			$sql = str_ireplace($search, $replace, $sql);
		}

		$args = $this->getParameters(func_get_args());
		if ($this->type === self::MYSQL && preg_match('/CREATE INDEX IF NOT EXISTS ([^ ]+) ON ([^ (]+)(.+)$/', $sql, $match)) {
			$indexQuery = "SELECT COUNT(1) AS indexthere ".
				"FROM INFORMATION_SCHEMA.STATISTICS ".
				"WHERE table_schema=DATABASE() ".
				"AND table_name=? ".
				"AND index_name=?";
			$tableName = preg_replace("/^`(.+?)`$/", "$1", $match[2]);
			$indexName = preg_replace("/^`(.+?)`$/", "$1", $match[1]);
			$indexQuery = $this->applySQLCompatFixes($indexQuery);
			$ps = $this->executeQuery($indexQuery, [$tableName, $indexName]);
			$indexResult = $ps->fetch(PDO::FETCH_ASSOC);
			if ($indexResult !== null && (int)$indexResult["indexthere"] > 0) {
				return 1;
			}
			$sql = "CREATE INDEX {$match[1]} ON {$match[2]}{$match[3]}";
			try {
				$sql = $this->applySQLCompatFixes($sql);
				$ps = $this->executeQuery($sql, $args);
				return $ps->rowCount();
			} catch (SQLException $e) {
				$this->logger->log(
					"WARN",
					"Unable to create index {$match[1]} on table {$match[2]}. For optimal speed, ".
					"consider upgrading to the latest MariaDB or use SQLite."
				);
				return 1;
			}
		}

		$sql = $this->applySQLCompatFixes($sql);
		$ps = $this->executeQuery($sql, $args);

		return $ps->rowCount();
	}

	/**
	 * Internal function to get additional parameters passed to exec()
	 */
	private function getParameters(array $args): array {
		array_shift($args);
		if (isset($args[0]) && is_array($args[0])) {
			return $args[0];
		}
		return $args;
	}

	/**
	 * Execute an SQL query, returning the statement object
	 *
	 * @throws SQLException when the query errors
	 */
	private function executeQuery(string $sql, array $params): PDOStatement {
		// $sql = $this->applySQLCompatFixes($sql);
		$this->lastQuery = $sql;
		$this->logger->log('DEBUG', $sql . " - " . print_r($params, true));

		try {
			$ps = $this->sql->prepare($sql);
			$count = 1;
			foreach ($params as $param) {
				if ($param === "NULL" || $param === null) {
					$ps->bindValue($count++, $param, PDO::PARAM_NULL);
				} elseif (is_bool($param)) {
					$ps->bindValue($count++, $param, PDO::PARAM_BOOL);
				} elseif (is_int($param)) {
					$ps->bindValue($count++, $param, PDO::PARAM_INT);
				} else {
					$ps->bindValue($count++, $param);
				}
			}
			$ps->execute();
			return $ps;
		} catch (PDOException $e) {
			if ($this->type === self::SQLITE && $e->errorInfo[1] === 17) {
				// fix for Sqlite schema changed error (retry the query)
				return $this->executeQuery($sql, $params);
			}
			if ($this->type === self::MYSQL && in_array($e->errorInfo[1], [1927, 2006], true)) {
				$this->logger->log(
					'WARNING',
					'DB had recoverable error: ' . trim($e->errorInfo[2]) . ' - reconnecting'
				);
				call_user_func($this->reconnect);
				return $this->executeQuery(...func_get_args());
			}
			throw new SQLException("Error: {$e->errorInfo[2]}\nQuery: $sql\nParams: " . json_encode($params, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), 0, $e);
		}
	}

	/**
	 * Start a transaction
	 */
	public function beginTransaction(): void {
		$this->logger->log('DEBUG', "Starting transaction");
		$this->sql->beginTransaction();
	}

	/**
	 * Commit a transaction
	 */
	public function commit(): void {
		$this->logger->log('DEBUG', "Committing transaction");
		$this->sql->commit();
	}

	/**
	 * Roll back a transaction
	 */
	public function rollback(): void {
		$this->logger->log('DEBUG', "Rolling back transaction");
		$this->sql->rollback();
	}

	/**
	 * Check if we're currently in a transaction
	 */
	public function inTransaction(): bool {
		return $this->sql->inTransaction();
	}

	/**
	 * Returns the ID of the last inserted row or sequence value
	 * @deprecated Will be removed in Nadybot 6.0
	 */
	public function lastInsertId(): int {
		return (int)$this->sql->lastInsertId();
	}

	/**
	 * Format SQL code by replacing placeholders like <myname>
	 */
	public function formatSql(string $sql): string {
		$sql = preg_replace_callback(
			"/<table:(.+?)>/",
			function (array $matches): string {
				return $this->tableNames[$matches[1]] ?? $matches[0];
			},
			$sql
		);
		$sql = str_replace("<dim>", (string)$this->dim, $sql);
		$sql = str_replace("<myname>", $this->botname, $sql);
		$sql = str_replace("<Myname>", ucfirst($this->botname), $sql);
		$sql = str_replace("<myguild>", $this->guild, $sql);

		return $sql;
	}

	/**
	 * Get the SQL query that was executed last
	 */
	public function getLastQuery(): ?string {
		return $this->lastQuery;
	}

	/**
	 * Loads an sql file if there is an update
	 *
	 * Will load the sql file with name $namexx.xx.xx.xx.sql if xx.xx.xx.xx is greater than settings[$name . "_sql_version"]
	 * If there is an sql file with name $name.sql it would load that one every time
	 * @deprecated Will be removed in Nadybot 6.0
	 */
	public function loadSQLFile(string $module, string $name, bool $forceUpdate=false): string {
		$name = strtolower($name);

		// only letters, numbers, underscores are allowed
		if (!preg_match('/^[a-z0-9_]+$/i', $name)) {
			$msg = "Invalid SQL file name: '$name' for module: '$module'!  Only numbers, letters, and underscores permitted!";
			$this->logger->log('ERROR', $msg);
			return $msg;
		}

		$settingName = $name . "_db_version";

		$dir = $this->util->verifyFilename($module);
		if (empty($dir)) {
			$msg = "Could not find module '$module'.";
			$this->logger->log('ERROR', $msg);
			return $msg;
		}
		$d = dir($dir);

		$currentVersion = false;
		if ($this->settingManager->exists($settingName)) {
			$currentVersion = $this->settingManager->get($settingName);
		}
		if ($currentVersion === false) {
			$currentVersion = 0;
		}

		$file = false;
		$maxFileVersion = 0;  // 0 indicates no version
		if ($d) {
			while (false !== ($entry = $d->read())) {
				if (is_file("$dir/$entry") && preg_match("/^" . $name . "([0-9.]*)\\.sql$/i", $entry, $arr)) {
					// If the file has no versioning in its filename, then we go off the modified timestamp
					if ($arr[1] == '') {
						$file = $entry;
						$maxFileVersion = filemtime("$dir/$file");
						break;
					}

					if ($this->util->compareVersionNumbers($arr[1], (string)$maxFileVersion) >= 0) {
						$maxFileVersion = $arr[1];
						$file = $entry;
					}
				}
			}
		}

		if ($file === false) {
			$msg = "No SQL file found with name '$name' in module '$module'!";
			$this->logger->log('ERROR', $msg);
			return $msg;
		}

		// make sure setting is verified so it doesn't get deleted
		$this->settingManager->add($module, $settingName, $settingName, 'noedit', 'text', "0");

		if (!$forceUpdate && $this->util->compareVersionNumbers((string)$maxFileVersion, (string)$currentVersion) <= 0) {
			$msg = "'$name' database already up to date! version: '$currentVersion'";
			$this->logger->log('DEBUG', $msg);
			return $msg;
		}
		$handle = @fopen("$dir/$file", "r");
		if ($handle === false) {
			$msg = "Could not load SQL file: '$dir/$file'";
			$this->logger->log('ERROR', $msg);
			return $msg;
		}
		try {
			$oldLine = '';
			while (($line = fgets($handle)) !== false) {
				$line = trim($line);
				// don't process comment lines or blank lines
				if ($line === '' || substr($line, 0, 1) === "#" || substr($line, 0, 2) === "--") {
					continue;
				}
				// If the line doesn't end with a ; we keep the value and add new lines
				// to it until we hit a ;
				if (substr($line, -1) !== ';') {
					$oldLine .= "$line\n";
				} else {
					$this->exec($oldLine.$line);
					$oldLine = '';
				}
			}

			$this->settingManager->save($settingName, (string)$maxFileVersion);

			if ($maxFileVersion != 0) {
				$msg = "Updated '$name' database from '$currentVersion' to '$maxFileVersion'";
				$this->logger->log('DEBUG', $msg);
			} else {
				$msg = "Updated '$name' database";
				$this->logger->log('DEBUG', $msg);
			}
		} catch (SQLException $e) {
			$msg = "Error loading sql file '$file': " . $e->getMessage();
			$this->logger->log('ERROR', $msg);
		}

		return $msg;
	}

	/**
	 * Check if a table exists in the database
	 * @deprecated Will be removed in Nadybot 6.0
	 */
	public function tableExists(string $table): bool {
		$table = $this->formatSql($table);
		return $this->schema()->hasTable($table);
	}

	/** @deprecated */
	public function columnExists(string $table, string $column): bool {
		return $this->schema()->hasColumn($table, $column);
	}

	/**
	 * Check if column $column in table $table is part of a unique constraint
	 * @deprecated will be removed in 6.0
	 */
	public function columnUnique(string $table, string $column): bool {
		if ($this->getType() === static::SQLITE) {
			$indexes = $this->query("PRAGMA index_list(`{$table}`)");
			foreach ($indexes as $index) {
				if (!$index->unique) {
					continue;
				}
				$indexColumns = $this->query("PRAGMA index_info(`{$index->name}`)");
				foreach ($indexColumns as $indexColumn) {
					if ($column !== $indexColumn->name) {
						return true;
					}
				}
			}
			return false;
		}
		$indexes = $this->queryRow("SHOW INDEXES FROM `{$table}` WHERE Column_name=? AND NOT Non_Unique", $column);
		return isset($indexes);
	}

	/**
	 * Insert a DBRow $row into the database table $table
	 */
	public function insert(string $table, DBRow $row, ?string $sequence=""): int {
		$refClass = new ReflectionClass($row);
		$props = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		$data = [];
		foreach ($props as $prop) {
			$comment = $prop->getDocComment();
			if ($comment !== false && preg_match("/@db:ignore/", $comment)) {
				continue;
			}
			if ($prop->isInitialized($row)) {
				$data[$prop->name] = $prop->getValue($row);
			}
		}
		$table = $this->formatSql($table);
		if ($sequence === null) {
			return $this->table($table)->insert($data) ? 1 : 0;
		}
		return $this->table($table)->insertGetId($data, $sequence);
	}

	/**
	 * Update a DBRow $row in the database table $table, using property $key in the where
	 */
	public function update(string $table, string $key, DBRow $row): int {
		$refClass = new ReflectionClass($row);
		$props = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		$updates = [];
		foreach ($props as $prop) {
			$comment = $prop->getDocComment();
			if ($comment !== false && preg_match("/@db:ignore/", $comment)) {
				continue;
			}
			if ($prop->isInitialized($row) && $prop->name !== $key) {
				$updates[$prop->name] = $prop->getValue($row);
			}
		}
		return $this->table($table)
			->where($key, $updates[$key])
			->update($updates);
	}

	/** Register a table name for a key */
	public function registerTableName(string $key, string $table): void {
		$this->tableNames[$key] = $table;
	}

	/**
	 * Get a schema builder instance.
	 */
	public function schema(?string $connection=null): SchemaBuilder {
		$schema = $this->capsule->schema($connection);
		$builder = new SchemaBuilder($schema);
		$builder->nadyDB = $this;
		return $builder;
	}

	/**
	 * Get a fluent query builder instance.
	 *
	 * @param \Closure|\Illuminate\Database\Query\Builder|string $table
	 * @param string|null $as
	 * @param string|null $connection
	 */
	public function table($table, ?string $as=null, ?string $connection=null): QueryBuilder {
		if (is_string($table)) {
			$table = $this->formatSql($table);
		}
		$builder = $this->capsule->table($table, $as, $connection);
		$myBuilder = new QueryBuilder($builder->getConnection(), $builder->getGrammar(), $builder->getProcessor());
		foreach ($builder as $attr => $value) {
			$myBuilder->{$attr} = $value;
		}
		$myBuilder->nadyDB = $this;
		return $myBuilder;
	}

	/**
	 * Makes "from" fetch from a subquery.
	 *
	 * @param  \Closure|\Illuminate\Database\Query\Builder|string  $query
	 * @param  string  $as
	 * @return $this
	 */
	public function fromSub($query, string $as): QueryBuilder {
		$query = $this->capsule->getConnection()->query()->fromSub($query, $as);
		$builder = new QueryBuilder($query->connection, $query->grammar, $query->processor);
		foreach ($query as $attr => $value) {
			$builder->{$attr} = $value;
		}
		$builder->nadyDB = $this;
		return $builder;
	}

	public function createMigrationTables(): void {
		$infoShown = false;
		foreach (["migrations", "migrations_<myname>"] as $table) {
			if ($this->schema()->hasTable($table)) {
				continue;
			}
			$this->schema()->create($table, function(Blueprint $table) {
				$table->id();
				$table->string('module');
				$table->string('migration');
				$table->integer('applied_at');
			});
			if ($infoShown) {
				continue;
			}
			if ($this->schema()->hasTable(AdminManager::DB_TABLE)) {
				$log = 'Your database is migrating to a new schema.';
			} else {
				$log = 'Your database is being initialized.';
			}
			$this->logger->log(
				'INFO',
				$log . ' ' . 'This can take a while; please be patient.'
			);
			$infoShown = true;
		}
	}

	/**
	 * Get a list of all DB migrations that were already applied in $module
	 * @return Collection<Migration>
	 */
	protected function getAppliedMigrations(string $module): Collection {
		$ownQuery = $this->table('migrations_<myname>')
			->where('module', $module);
		$sharedQuery = $this->table('migrations')
				->where('module', $module);
		return $ownQuery->union($sharedQuery)
			->orderBy('migration')->asObj(Migration::class);
	}

	/** Check if a specific migration has already been applied */
	public function hasAppliedMigration(string $module, string $migration): bool {
		return $this->table('migrations_<myname>')
				->where('module', $module)
				->where('migration', $migration)
				->exists()
			||
			$this->table('migrations')
				->where('module', $module)
				->where('migration', $migration)
				->exists();
	}

	/** Load and apply all migrations for $module from directory $dir and return if any were applied */
	public function loadMigrations(string $module, string $dir): bool {
		$this->createMigrationTables();
		$files = glob("{$dir}/*.php");
		$applied = $this->getAppliedMigrations($module);
		$numApplied = 0;
		foreach ($files as $file) {
			$baseName = basename($file, '.php');
			if ($applied->contains("migration", $baseName)) {
				continue;
			}
			$this->applyMigration($module, $file);
			$numApplied++;
		}
		return $numApplied > 0;
	}

	protected function applyMigration(string $module, string $file): void {
		$baseName = basename($file, '.php');
		$old = get_declared_classes();
		try {
			require_once $file;
		} catch (Throwable $e) {
			$this->logger->log('ERROR', "Cannot parse $file: " . $e->getMessage());
			return;
		}
		$new = array_diff(get_declared_classes(), $old);
		$table = $this->formatSql(
			preg_match("/\.shared/", $baseName) ? "migrations" : "migrations_<myname>"
		);
		foreach ($new as $class) {
			if (!is_subclass_of($class, SchemaMigration::class)) {
				continue;
			}
			$obj = new $class();
			Registry::injectDependencies($obj);
			try {
				$this->logger->log('DEBUG', "Running migration {$class}");
				$obj->migrate($this->logger, $this);
			} catch (Throwable $e) {
				$this->logger->log(
					'ERROR',
					"Error executing {$class}::migrate(): ".
						$e->getMessage()
				);
				continue;
			}
			$this->table($table)->insert([
				'module' => $module,
				'migration' => $baseName,
				'applied_at' => time(),
			]);
		}
	}

	/**
	 * Load a CSV file $file into table $table
	 * @param string $module The module to which this file belongs
	 * @param string $file The full path to the CSV file
	 * @param string $table Name of the table to insert the data into
	 * @return bool trueif inserted, false if already up-to-date
	 * @throws Exception
	 */
	public function loadCSVFile(string $module, string $file): bool {
		$fileBase = pathinfo($file, PATHINFO_FILENAME);
		$table = $fileBase;
		if (!@file_exists($file)) {
			throw new Exception("The CSV-file {$file} was not found.");
		}
		$version = filemtime($file) ?: 0;
		$handle = fopen($file, 'r');
		while ($handle !== false && !feof($handle)) {
			$line = fgets($handle);
			if ($line === false || substr($line, 0, 1) !== "#") {
				break;
			}
			$line = trim($line);
			if (!preg_match("/^#\s*(.+?):\s*(.+)$/i", $line, $matches)) {
				continue;
			}
			$value = $matches[2];
			switch (strtolower($matches[1])) {
				case "replaces":
					$where = preg_split("/\s*=\s*/", $value);
					break;
				case "version":
					$version = $value;
					break;
				case "table":
					$table = $value;
					break;
				case "requires":
					if (!$this->hasAppliedMigration($module, $value)) {
						throw new Exception("The CSV-file {$file} is incompatible with your database schema version");
					}
					break;
			}
		}
		if ($handle !== false) {
			fclose($handle);
		}
		$settingName = strtolower("{$fileBase}_db_version");
		$currentVersion = false;
		if ($this->settingManager->exists($settingName)) {
			$currentVersion = $this->settingManager->get($settingName);
		}
		if ($currentVersion === false) {
			$currentVersion = 0;
		}
		// make sure setting is verified so it doesn't get deleted
		$this->settingManager->add($module, $settingName, $settingName, 'noedit', 'text', "0");

		if ($this->table($table)->exists() && $this->util->compareVersionNumbers((string)$version, (string)$currentVersion) <= 0) {
			$msg = "'{$table}' database already up to date! version: '$currentVersion'";
			$this->logger->log('DEBUG', $msg);
			return false;
		}
		$this->logger->log('DEBUG', "Inserting {$file}");
		$csv = new Reader($file);
		$items = [];
		$itemCount = 0;
		try {
			if (isset($where) && count($where)) {
				$this->table($table)->where(...$where)->delete();
			} else {
				$this->table($table)->delete();
			}
			foreach ($csv->items() as $item) {
				$itemCount++;
				$items []= $item;
				if (count($items) > 900) {
					$this->table($table)->insert($items);
					$items = [];
				}
			}
			if (count($items) > 0) {
				$this->table($table)->insert($items);
			}
		} catch (PDOException $e) {
			$this->logger->log('ERROR', $e->getMessage());
			throw $e;
		}
		$this->settingManager->save($settingName, (string)$version);

		if ($version !== 0) {
			$msg = "Updated '{$table}' database from '{$currentVersion}' to '{$version}'";
			$this->logger->log('DEBUG', $msg);
		} else {
			$msg = "Updated '{$table}' database";
			$this->logger->log('DEBUG', $msg);
		}
		return true;
	}

	/**
	 * Generate an SQL query from a column and a list of criteria
	 *
	 * @param string[] $params An array of strings that $column must contain (or not contain if they start with "-")
	 * @param string $column The table column to test against
	 * @return array<string,string[]> ["$column LIKE ? AND $column NOT LIKE ? AND $column LIKE ?", ['%a%', '%b%', '%c%']]
	 */
	public function addWhereFromParams(QueryBuilder $query, array $params, string $column, string $boolean='and'): void {
		$closure = function (QueryBuilder $query) use ($params, $column) {
			foreach ($params as $key => $value) {
				if ($value[0] == "-" && strlen($value) > 1) {
					$value = substr($value, 1);
					$op = "not like";
				} else {
					$op = "like";
				}
				$query->whereRaw(
					$query->colFunc("LOWER", $column) . " {$op} ?",
					"%" . strtolower($value) . "%"
				);
			}
		};
		$query->where($closure, null, null, $boolean);
	}
}
