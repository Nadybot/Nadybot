<?php

namespace Budabot\Core;

use PDO;
use PDOException;
use Exception;

/**
 * @Instance
 */
class DB {

	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;

	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;

	/**
	 * The database type: mysql/sqlite
	 *
	 * @var string $type
	 */
	private $type;

	/**
	 * The PDO object to talk to the database
	 *
	 * @var \PDO $sql
	 */
	private $sql;

	/**
	 * The name of the bot
	 *
	 * @var string $botname
	 */
	private $botname;

	/**
	 * The dimension
	 *
	 * @var int $dim
	 */
	private $dim;

	/** @var string $guild */
	private $guild;
	/** @var string $lastQuery */
	private $lastQuery;
	/** @var bool $inTransaction */
	private $inTransaction = false;

	/** @var \Budabot\Core\LoggerWrapper $logger */
	private $logger;

	const MYSQL = 'mysql';
	const SQLITE = 'sqlite';

	public function __construct() {
		$this->logger = new LoggerWrapper('SQL');
	}

	/**
	 * Connect to the database
	 *
	 * @param string $type Database type: mysql or sqlite
	 * @param string $dbName Name of the database
	 * @param string $host Hostname (mysql) or directory (sqlite)
	 * @param string $user username
	 * @param string $pass Password
	 * @return void
	 * @throws Exception for unsupported database types
	 */
	public function connect($type, $dbName, $host=null, $user=null, $pass=null) {
		global $vars;
		$this->type = strtolower($type);
		$this->botname = strtolower($vars["name"]);
		$this->dim = $vars["dimension"];
		$this->guild = str_replace("'", "''", $vars["my_guild"]);

		if ($this->type == self::MYSQL) {
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
		} elseif ($this->type == self::SQLITE) {
			if ($host == null || $host == "" || $host == "localhost") {
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
	 * Get the configurd database type
	 *
	 * @return string
	 */
	public function getType() {
		return $this->type;
	}

	/**
	 * Execute an SQL statement and return the first row as object or null if no results
	 *
	 * @param string $sql The SQL query
	 * @return \StdClass|null The first row or null if no results
	 */
	public function queryRow($sql) {
		$sql = $this->formatSql($sql);

		$args = $this->getParameters(func_get_args());

		$ps = $this->executeQuery($sql, $args);
		$result = $ps->fetchAll(PDO::FETCH_CLASS, 'budabot\core\DBRow');

		if (count($result) == 0) {
			return null;
		} else {
			return $result[0];
		}
	}

	/**
	 * Execute an SQL statement and return all rows as an array of objects
	 *
	 * @param string $sql The SQL query
	 * @return \Budabot\Core\DBRow[] All returned rows
	 */
	public function query($sql) {
		$sql = $this->formatSql($sql);

		$args = $this->getParameters(func_get_args());

		$ps = $this->executeQuery($sql, $args);
		return $ps->fetchAll(PDO::FETCH_CLASS, 'budabot\core\DBRow');
	}

	/**
	 * Execute a query and return the number of affected rows
	 *
	 * @param string $sql The query to execute
	 * @return int Number of affected rows
	 */
	public function exec($sql) {
		$sql = $this->formatSql($sql);

		if (substr_compare($sql, "create", 0, 6, true) == 0) {
			if ($this->type == self::MYSQL) {
				$sql = str_ireplace("AUTOINCREMENT", "AUTO_INCREMENT", $sql);
			} elseif ($this->type == self::SQLITE) {
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
	 *
	 * @param mixed[] $args
	 * @return mixed[]
	 */
	private function getParameters($args) {
		array_shift($args);
		if (isset($args[0]) && is_array($args[0])) {
			return $args[0];
		} else {
			return $args;
		}
	}

	/**
	 * Execute an SQL query, returning the statement object
	 *
	 * @param string $sql The SQL query, optionally containing placeholders
	 * @param mixed[] $params An array of parameters to fill the placeholders in $sql
	 * @return \PDOStatement The statement object
	 * @throws \SQLException when the query errors
	 */
	private function executeQuery($sql, $params) {
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
			if ($this->type == self::SQLITE && $e->errorInfo[1] == 17) {
				// fix for Sqlite schema changed error (retry the query)
				return $this->executeQuery($sql, $params);
			}
			throw new SQLException("{$e->errorInfo[2]} in: $sql - " . print_r($params, true), 0, $e);
		}
	}

	/**
	 * Start a transaction
	 *
	 * @return void
	 */
	public function beginTransaction() {
		$this->logger->log('DEBUG', "Starting transaction");
		$this->inTransaction = true;
		$this->sql->beginTransaction();
	}

	/**
	 * Commit a transaction
	 *
	 * @return void
	 */
	public function commit() {
		$this->logger->log('DEBUG', "Committing transaction");
		$this->inTransaction = false;
		$this->sql->Commit();
	}

	/**
	 * Roll back a transaction
	 *
	 * @return void
	 */
	public function rollback() {
		$this->logger->log('DEBUG', "Rolling back transaction");
		$this->inTransaction = false;
		$this->sql->rollback();
	}

	/**
	 * Check if we're currently in a transaction
	 *
	 * @return bool
	 */
	public function inTransaction() {
		return $this->inTransaction;
	}

	/**
	 * Returns the ID of the last inserted row or sequence value
	 *
	 * @return string
	 */
	public function lastInsertId() {
		return $this->sql->lastInsertId();
	}

	/**
	 * Format SQL code by replacing placeholders like <myname>
	 *
	 * @param string $sql The SQL query to format
	 * @return string The formatted SQL query
	 */
	public function formatSql($sql) {
		$sql = str_replace("<dim>", $this->dim, $sql);
		$sql = str_replace("<myname>", $this->botname, $sql);
		$sql = str_replace("<myguild>", $this->guild, $sql);

		return $sql;
	}

	/**
	 * Get the SQL query that was executed last
	 *
	 * @return string The last SQL query
	 */
	public function getLastQuery() {
		return $this->lastQuery;
	}

	/**
	 * Loads an sql file if there is an update
	 *
	 * Will load the sql file with name $namexx.xx.xx.xx.sql if xx.xx.xx.xx is greater than settings[$name . "_sql_version"]
	 * If there is an sql file with name $name.sql it would load that one every time
	 *
	 * @param string $module The name of the module for which to load an SQL file
	 * @param string $name The name of the SQL file to load
	 * @param bool $forceUpdate Set this to true to always load the file, even if this version is already installed
	 * @return string A message describing what happened
	 */
	public function loadSQLFile($module, $name, $forceUpdate=false) {
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

		if ($this->settingManager->exists($settingName)) {
			$currentVersion = $this->settingManager->get($settingName);
		} else {
			$currentVersion = false;
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

					if ($this->util->compareVersionNumbers($arr[1], $maxFileVersion) >= 0) {
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
		$this->settingManager->add($module, $settingName, $settingName, 'noedit', 'text', 0);

		if ($forceUpdate || $this->util->compareVersionNumbers($maxFileVersion, $currentVersion) > 0) {
			$handle = @fopen("$dir/$file", "r");
			if ($handle) {
				try {
					$oldLine = '';
					while (($line = fgets($handle)) !== false) {
						$line = trim($line);
						// don't process comment lines or blank lines
						if ($line != '' && substr($line, 0, 1) != "#" && substr($line, 0, 2) != "--") {
							// If the line doesn't end with a ; we keep the value and add new lines
							// to it until we hit a ;
							if (substr($line, -1) !== ';') {
								$oldLine .= "$line\n";
							} else {
								$this->exec($oldLine.$line);
								$oldLine = '';
							}
						}
					}

					$this->settingManager->save($settingName, $maxFileVersion);

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
			} else {
				$msg = "Could not load SQL file: '$dir/$file'";
				$this->logger->log('ERROR', $msg);
			}
		} else {
			$msg = "'$name' database already up to date! version: '$currentVersion'";
			$this->logger->log('DEBUG', $msg);
		}

		return $msg;
	}

	/**
	 * Check if a table exists in the database
	 *
	 * @param string $tableName
	 * @return bool
	 */
	public function tableExists($tableName) {
		if ($this->getType() === static::SQLITE) {
			return $this->queryRow(
				"SELECT COUNT(*) AS `exists` ".
				"FROM sqlite_master WHERE type=? AND name=?",
				"table",
				$tableName
			)->exists > 0;
		}
		return $this->queryRow(
			"SELECT COUNT(*) AS `exists` FROM information_schema.TABLES ".
			"WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
			$tableName
		)->exists > 0;
	}
}
