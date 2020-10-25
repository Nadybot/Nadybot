<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * @Instance
 */
class DB {

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
	 * The name of the bot
	 */
	private string $botname;

	/**
	 * The dimension
	 */
	private int $dim;

	private string $guild;
	private string $lastQuery;
	private bool $inTransaction = false;
	private array $meta = [];
	private array $metaTypes = [];

	private LoggerWrapper $logger;

	public const MYSQL = 'mysql';
	public const SQLITE = 'sqlite';

	public function __construct() {
		$this->logger = new LoggerWrapper('SQL');
	}

	/**
	 * Connect to the database
	 *
	 * @throws Exception for unsupported database types
	 */
	public function connect(string $type, string $dbName, ?string $host=null, ?string $user=null, ?string $pass=null): void {
		global $vars;
		$this->type = strtolower($type);
		$this->botname = strtolower($vars["name"]);
		$this->dim = $vars["dimension"];
		$this->guild = str_replace("'", "''", $vars["my_guild"]);

		if ($this->type === self::MYSQL) {
			$this->sql = new PDO("mysql:dbname=$dbName;host=$host", $user, $pass);
			$this->sql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->exec("SET sql_mode = 'TRADITIONAL,NO_BACKSLASH_ESCAPES'");
			$this->exec("SET time_zone = '+00:00'");

			$mysqlVersion = $this->sql->getAttribute(PDO::ATTR_SERVER_VERSION);

			// for MySQL 5.5 and later, use 'default_storage_engine'
			// for previous versions use 'storage_engine'
			if (version_compare($mysqlVersion, "5.5") >= 0) {
				$this->exec("SET default_storage_engine = MyISAM");
			} else {
				$this->exec("SET storage_engine = MyISAM");
			}
		} elseif ($this->type === self::SQLITE) {
			if ($host === null || $host === "" || $host === "localhost") {
				$dbName = "./data/$dbName";
			} else {
				$dbName = "$host/$dbName";
			}

			$this->sql = new PDO("sqlite:".$dbName);
			$this->sql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} else {
			throw new Exception("Invalid database type: '$type'.  Expecting '" . self::MYSQL . "' or '" . self::SQLITE . "'.");
		}
	}

	/**
	 * Get the configured database type
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * Execute an SQL statement and return the first row as object or null if no results
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
			continue;
			if (in_array($colMeta['native_type'], ["integer", "TINY", "LONG", "SHORT"])) {
				$colName = $colMeta['name'];
				$refProp = new ReflectionProperty($row, $colName);
				$refProp->setAccessible(true);
				if (
					$colMeta['native_type'] === 'TINY'
					|| (isset($colMeta['sqlite:decl_type'])
						&& in_array($colMeta['sqlite:decl_type'], ['BOOLEAN', 'TINYINT(1)']))
				) {
					$this->meta[$metaKey] []= function(object $row) use ($refProp) {
						$stringValue = $refProp->getValue($row);
						if ($stringValue !== null) {
							$refProp->setValue($row, (bool)$stringValue);
						}
					};
				} else {
					$this->meta[$metaKey] []= function(object $row) use ($refProp) {
						$stringValue = $refProp->getValue($row);
						if ($stringValue !== null) {
							$refProp->setValue($row, (int)$stringValue);
						}
					};
				}
			}
		}
		return $this->meta[$metaKey];
	}

	/**
	 * Execute an SQL statement and return all rows as an array of objects
	 *
	 * @return \Nadybot\Core\DBRow[] All returned rows
	 */
	public function query(string $sql): array {
		$sql = $this->formatSql($sql);

		$args = $this->getParameters(func_get_args());

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
	 */
	public function fetchAll(string $className, string $sql, ...$args): array {
		$sql = $this->formatSql($sql);

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
		if (!in_array($colMeta['native_type'], ["integer", "TINY", "LONG", "NEWDECIMAL"])
			&& !in_array($colMeta["sqlite:decl_type"] ?? null, ["INT, BOOLEAN, TINYINT(1)"])) {
			return null;
		}
		if (
			$colMeta['native_type'] === 'TINY'
			|| (isset($colMeta['sqlite:decl_type'])
				&& in_array($colMeta['sqlite:decl_type'], ['BOOLEAN', 'TINYINT(1)']))
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
			$type = $this->guessVarTypeFromReflection($refClass, $colName)
				?? $this->guessVarTypeFromColMeta($colMeta, $colName);
			if ($type === "bool") {
				$row->{$colName} = (bool)$values[$col];
			} elseif ($type === "int") {
				$row->{$colName} = (int)$values[$col];
			} elseif ($type === "float") {
				$row->{$colName} = (float)$values[$col];
			} else {
				$row->{$colName} = $values[$col];
			}
		}
		return $row;
	}

	/**
	 * Execute a query and return the number of affected rows
	 */
	public function exec(string $sql): int {
		$sql = $this->formatSql($sql);

		if (substr_compare($sql, "create", 0, 6, true) === 0) {
			if ($this->type === self::MYSQL) {
				$sql = str_ireplace("AUTOINCREMENT", "AUTO_INCREMENT", $sql);
			} elseif ($this->type === self::SQLITE) {
				$sql = str_ireplace("AUTO_INCREMENT", "AUTOINCREMENT", $sql);
				$sql = str_ireplace(" INT ", " INTEGER ", $sql);
			}
		}

		$args = $this->getParameters(func_get_args());

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
		$this->lastQuery = $sql;
		$this->logger->log('DEBUG', $sql . " - " . print_r($params, true));

		try {
			$ps = $this->sql->prepare($sql);
			$count = 1;
			foreach ($params as $param) {
				if ($param === "NULL") {
					$ps->bindValue($count++, $param, PDO::PARAM_NULL);
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
			throw new SQLException("Error: {$e->errorInfo[2]}\nQuery: $sql\nParams: " . json_encode($params, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), 0, $e);
		}
	}

	/**
	 * Start a transaction
	 */
	public function beginTransaction(): void {
		$this->logger->log('DEBUG', "Starting transaction");
		$this->inTransaction = true;
		$this->sql->beginTransaction();
	}

	/**
	 * Commit a transaction
	 */
	public function commit(): void {
		$this->logger->log('DEBUG', "Committing transaction");
		$this->inTransaction = false;
		$this->sql->Commit();
	}

	/**
	 * Roll back a transaction
	 */
	public function rollback(): void {
		$this->logger->log('DEBUG', "Rolling back transaction");
		$this->inTransaction = false;
		$this->sql->rollback();
	}

	/**
	 * Check if we're currently in a transaction
	 */
	public function inTransaction(): bool {
		return $this->inTransaction;
	}

	/**
	 * Returns the ID of the last inserted row or sequence value
	 */
	public function lastInsertId(): int {
		return (int)$this->sql->lastInsertId();
	}

	/**
	 * Format SQL code by replacing placeholders like <myname>
	 */
	public function formatSql(string $sql): string {
		$sql = str_replace("<dim>", $this->dim, $sql);
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
	 */
	public function tableExists(string $tableName): bool {
		if ($this->getType() === static::SQLITE) {
			return $this->queryRow(
				"SELECT COUNT(*) AS `exists` ".
				"FROM sqlite_master WHERE type=? AND name=?",
				"table",
				$this->formatSql($tableName)
			)->exists > 0;
		}
		return $this->queryRow(
			"SELECT COUNT(*) AS `exists` FROM information_schema.TABLES ".
			"WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
			$this->formatSql($tableName)
		)->exists > 0;
	}

	public function columnExists(string $table, string $column): bool {
		if ($this->getType() === static::SQLITE) {
			return $this->queryRow(
				"SELECT COUNT(*) AS `exists` FROM pragma_table_info(?) WHERE name=?",
				$this->formatSql($table),
				$column
			)->exists > 0;
		}
		return $this->queryRow(
			"SELECT COUNT(*) AS `exists` FROM information_schema.columns ".
			"WHERE table_name = ? AND column_name = ?",
			$this->formatSql($table),
			$column
		)->exists > 0;
	}
}
