<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use DateTime;
use PDO;
use PDOException;
use Exception;
use ReflectionClass;
use ReflectionProperty;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nadybot\Core\CSV\Reader;
use Nadybot\Core\DBSchema\Migration;
use Throwable;

#[NCA\Instance]
class DB {

	public const SQLITE_MIN_VERSION = "3.23.0";

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
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

	#[NCA\Logger]
	public LoggerWrapper $logger;

	protected array $sqlReplacements = [];
	protected array $sqlRegexpReplacements = [];
	protected array $sqlCreateReplacements = [];

	protected array $tableNames = [];

	public int $maxPlaceholders = 9000;

	public const MYSQL = 'mysql';
	public const SQLITE = 'sqlite';
	public const POSTGRESQL = 'postgresql';
	public const MSSQL = 'mssql';

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
		global $vars;
		$errorShown = isset($this->sql);
		unset($this->sql);
		$this->dbName = $dbName;
		$this->type = strtolower($type);
		$this->botname = strtolower($vars["name"]);
		$this->dim = $vars["dimension"];
		$this->guild = str_replace("'", "''", $vars["my_guild"]??"");
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
						$this->logger->error(
							"Cannot connect to the MySQL db at {$host}: ".
							trim($e->errorInfo[2])
						);
						$this->logger->notice(
							"Will keep retrying until the db is back up again"
						);
						$errorShown = true;
					}
					sleep(1);
				}
			} while (!isset($this->sql));
			if ($errorShown) {
				$this->logger->notice("Database connection re-established");
			}
			$this->sql->exec("SET sql_mode = 'TRADITIONAL,NO_BACKSLASH_ESCAPES'");
			$this->sql->exec("SET time_zone = '+00:00'");
			$this->sqlCreateReplacements[" AUTOINCREMENT"] = " AUTO_INCREMENT";
		} elseif ($this->type === self::SQLITE) {
			if ($host === null || $host === "" || $host === "localhost") {
				$dbName = "./data/$dbName";
			} else {
				$dbName = "$host/$dbName";
			}
			if (!@file_exists($dbName)) {
				if (!touch($dbName)) {
					$this->logger->error(
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
			if (BotRunner::isWindows()) {
				$this->maxPlaceholders = 999;
			}

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
						$this->logger->error(
							"Cannot connect to the PostgreSQL db at {$host}: ".
							trim($e->errorInfo[2])
						);
						$this->logger->notice(
							"Will keep retrying until the db is back up again"
						);
						$errorShown = true;
					}
					sleep(1);
				}
			} while (!isset($this->sql));
			if ($errorShown) {
				$this->logger->notice("Database connection re-established");
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
						$this->logger->error(
							"Cannot connect to the MSSQL db at {$host}: ".
							trim($e->errorInfo[2])
						);
						$this->logger->notice(
							"Will keep retrying until the db is back up again"
						);
						$errorShown = true;
					}
					sleep(1);
				}
			} while (!isset($this->sql));
			if ($errorShown) {
				$this->logger->notice("Database connection re-established");
			}
		} else {
			throw new Exception("Invalid database type: '$type'.  Expecting '" . self::MYSQL . "', '". self::POSTGRESQL . "' or '" . self::SQLITE . "'.");
		}
		$this->capsule->setAsGlobal();
		/** @psalm-suppress TooManyArguments */
		$this->capsule->setFetchMode(PDO::FETCH_CLASS, DBRow::class);
		$this->capsule->getConnection()->beforeExecuting(
			function(string $query, array $bindings, Connection $connection): void {
				$this->logger->debug(
					$query,
					[
						"params" => $bindings,
						"driver" => $this->sql->getAttribute(PDO::ATTR_DRIVER_NAME),
						"version" => $this->sql->getAttribute(PDO::ATTR_SERVER_VERSION)
					]
				);
			}
		);
	}

	/**
	 * Get the configured database type
	 */
	public function getType(): string {
		return $this->type;
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
	 * Start a transaction
	 */
	public function beginTransaction(): void {
		$this->logger->info("Starting transaction");
		$this->sql->beginTransaction();
	}

	/**
	 * Commit a transaction
	 */
	public function commit(): void {
		$this->logger->info("Committing transaction");
		try {
			$this->sql->commit();
		} catch (PDOException $e) {
			$this->logger->info("No active transaction to commit");
		}
	}

	/**
	 * Roll back a transaction
	 */
	public function rollback(): void {
		$this->logger->info("Rolling back transaction");
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
				if ($data[$prop->name] instanceof DateTime) {
					$data[$prop->name] = $data[$prop->name]->getTimestamp();
				}
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
	 *
	 * @param string $table Name of the database table
	 * @param string|string[] $key Name of the primary key or array of the primary keys
	 * @param DBRow $row The data to update
	 * @return int Number of updates records
	 */
	public function update(string $table, $key, DBRow $row): int {
		/** @psalm-suppress DocblockTypeContradiction */
		if (!is_string($key) && !is_array($key)) {
			throw new InvalidArgumentException("argument 2 to " . __FUNCTION__ . " (\$key) must either be a string or an array of strings.");
		}
		$refClass = new ReflectionClass($row);
		$props = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		$updates = [];
		foreach ($props as $prop) {
			$comment = $prop->getDocComment();
			if ($comment !== false && preg_match("/@db:ignore/", $comment)) {
				continue;
			}
			if ($prop->isInitialized($row)) {
				$updates[$prop->name] = $prop->getValue($row);
				if ($updates[$prop->name] instanceof DateTime) {
					$updates[$prop->name] = $updates[$prop->name]->getTimestamp();
				}
			}
		}
		$query = $this->table($table);
		foreach ((array)$key as $k) {
			$query->where($k, $row->{$k});
		}
		return $query->update($updates);
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
		$builder->logger = new LoggerWrapper("Core/QueryBuilder");
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
		$myBuilder->logger = new LoggerWrapper("Core/QueryBuilder");
		return $myBuilder;
	}

	/**
	 * Makes "from" fetch from a subquery.
	 *
	 * @param  \Closure|\Illuminate\Database\Query\Builder|string  $query
	 * @param  string  $as
	 * @return QueryBuilder
	 */
	public function fromSub($query, string $as): QueryBuilder {
		$query = $this->capsule->getConnection()->query()->fromSub($query, $as);
		$builder = new QueryBuilder($query->connection, $query->grammar, $query->processor);
		foreach ($query as $attr => $value) {
			$builder->{$attr} = $value;
		}
		$builder->nadyDB = $this;
		$builder->logger = new LoggerWrapper("Core/QueryBuilder");
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
				$log = 'Migrating database to a new schema.';
			} else {
				$log = 'Initializing database.';
			}
			$this->logger->notice(
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
			$this->logger->error("Cannot parse $file: " . $e->getMessage(), ["exception" => $e]);
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
				$this->logger->info("Running migration {$class}");
				$obj->migrate($this->logger, $this);
			} catch (Throwable $e) {
				$this->logger->error(
					"Error executing {$class}::migrate(): ".
						$e->getMessage(),
					["exception" => $e]
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
		$this->settingManager->add(
			module: $module,
			name: $settingName,
			description: $settingName,
			mode: 'noedit',
			type: 'text',
			value: "0"
		);

		if ($this->table($table)->exists() && $this->util->compareVersionNumbers((string)$version, (string)$currentVersion) <= 0) {
			$msg = "'{$table}' database already up to date! version: '$currentVersion'";
			$this->logger->info($msg);
			return false;
		}
		$this->logger->info("Inserting {$file}");
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
				if ((count($items)+1) * count($item) > $this->maxPlaceholders) {
					$this->table($table)->chunkInsert($items);
					$items = [];
				}
			}
			if (count($items) > 0) {
				$this->table($table)->chunkInsert($items);
			}
		} catch (PDOException $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			throw $e;
		}
		$this->settingManager->save($settingName, (string)$version);

		if ($version !== 0) {
			$msg = "Updated '{$table}' database from '{$currentVersion}' to '{$version}'";
			$this->logger->info($msg);
		} else {
			$msg = "Updated '{$table}' database";
			$this->logger->info($msg);
		}
		return true;
	}

	/**
	 * Generate an SQL query from a column and a list of criteria
	 *
	 * @param string[] $params An array of strings that $column must contain (or not contain if they start with "-")
	 * @param string $column The table column to test against
	 * @return void
	 */
	public function addWhereFromParams(QueryBuilder $query, array $params, string $column, string $boolean='and'): void {
		$closure = function (QueryBuilder $query) use ($params, $column): void {
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
