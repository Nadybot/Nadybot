<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\ByteStream\splitLines;
use function Amp\delay;
use function Safe\{class_implements, preg_match, preg_split};

use Amp\File\FilesystemException;
use BackedEnum;
use DateTime;
use Exception;
use Illuminate\Database\{
	Capsule\Manager as Capsule,
	Connection,
	Schema\Blueprint,
};
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes\Migration as AttributesMigration;
use Nadybot\Core\{
	Attributes as NCA,
	CSV\Reader,
	Config\BotConfig,
	DBSchema\Migration,
	Migration as CoreMigration,
};
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use Revolt\EventLoop;

use Throwable;

#[NCA\Instance]
#[NCA\HasMigrations(module: "Core")]
class DB {
	public const SQLITE_MIN_VERSION = "3.24.0";

	public const MYSQL = 'mysql';
	public const SQLITE = 'sqlite';
	public const POSTGRESQL = 'postgresql';
	public const MSSQL = 'mssql';

	public int $maxPlaceholders = 9000;

	/** The database name */
	protected string $dbName;

	/** @var array<string,string> */
	protected array $sqlReplacements = [];

	/** @var array<string,string> */
	protected array $sqlRegexpReplacements = [];

	/** @var array<string,string> */
	protected array $sqlCreateReplacements = [];

	/** @var array<string,string> */
	protected array $tableNames = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Filesystem $fs;

	/** The database type: mysql/sqlite */
	private DB\Type $type;

	/** The PDO object to talk to the database */
	private ?PDO $sql = null;

	/** The low-level Capsule manager object */
	private Capsule $capsule;

	/** Get the lowercased name of the bot */
	public function getBotname(): string {
		return strtolower($this->config->main->character);
	}

	/** Get the correct name of the bot */
	public function getMyname(): string {
		return ucfirst($this->getBotname());
	}

	/** Get the correct guild name of the bot */
	public function getMyguild(): string {
		return ucfirst($this->config->general->orgName);
	}

	/** Get the dimension id of the bot */
	public function getDim(): int {
		return $this->config->main->dimension;
	}

	public function getVersion(): string {
		if (!isset($this->sql)) {
			throw new Exception("You are not connected to any database.");
		}
		$version = $this->sql->getAttribute(PDO::ATTR_SERVER_VERSION);
		if (!isset($version) || !is_string($version)) {
			throw new Exception("Your database is not supported");
		}
		return $this->config->database->type->name . " {$version}";
	}

	/**
	 * Connect to the database
	 *
	 * @throws Exception for unsupported database types
	 */
	public function connect(Config\Database $config): void {
		$errorShown = isset($this->sql);
		$this->sql = null;
		$this->dbName = $config->name;
		$this->type = $config->type;
		$this->capsule = new Capsule();

		if ($this->type === DB\Type::MySQL) {
			do {
				$this->sql = null;
				try {
					$this->capsule->addConnection([
						'driver' => 'mysql',
						'host' => $config->host,
						'database' => $config->name,
						'username' => $config->username,
						'password' => $config->password,
						'charset' => 'utf8',
						'collation' => 'utf8_unicode_ci',
						'prefix' => '',
					]);
					$this->sql = $this->capsule->getConnection()->getPdo();
				} catch (PDOException $e) {
					if (!$errorShown) {
						$e->errorInfo ??= [$e->getCode(), $e->getCode(), $e->getMessage()];
						$this->logger->error(
							"Cannot connect to the MySQL db at {$config->host}: ".
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
		} elseif ($this->type === DB\Type::SQLite) {
			if ($config->host === "" || $config->host === "localhost") {
				$dbName = "./data/{$config->name}";
			} else {
				$dbName = "{$config->host}/{$config->name}";
			}
			if (!$this->fs->exists($dbName)) {
				try {
					$this->fs->touch($dbName);
				} catch (FilesystemException $e) {
					$this->logger->alert(
						"Unable to create the dababase '{database}': {error}. Check that the directory ".
						"exists and is writable by the current user.",
						[
							"database" => $dbName,
							"error" => $e->getMessage(),
							"exception" => $e,
						]
					);
					exit(10);
				}
			}
			$this->capsule->addConnection([
				'driver' => 'sqlite',
				'database' => $dbName,
				'prefix' => '',
			]);
			$this->sql = $this->capsule->getConnection()->getPdo();
			$this->maxPlaceholders = 999;

			/** @var ?string */
			$sqliteVersion = $this->sql->getAttribute(PDO::ATTR_SERVER_VERSION);
			if (!isset($sqliteVersion) || version_compare($sqliteVersion, static::SQLITE_MIN_VERSION, "<")) {
				$this->logger->critical(
					"You need at least SQLite {minVersion} for Nadybot. ".
					"Your system is using {version}.",
					[
						"minVersion" => static::SQLITE_MIN_VERSION,
						"version" => $sqliteVersion,
					]
				);
				exit(1);
			}
			$this->sqlCreateReplacements[" AUTO_INCREMENT"] = " AUTOINCREMENT";
			$this->sqlCreateReplacements[" INT "] = " INTEGER ";
			$this->sqlCreateReplacements[" INT,"] = " INTEGER,";
			// SQLite 3.37.0 adds strict tables. These do actual type checking
			$strictGrammar = new class () extends \Illuminate\Database\Schema\Grammars\SQLiteGrammar {
				public function compileCreate(\Illuminate\Database\Schema\Blueprint $blueprint, \Illuminate\Support\Fluent $command) {
					return parent::compileCreate($blueprint, $command) . ' strict';
				}

				protected function typeChar(\Illuminate\Support\Fluent $column) {
					return 'text';
				}

				protected function typeString(\Illuminate\Support\Fluent $column) {
					return 'text';
				}

				protected function typeFloat(\Illuminate\Support\Fluent $column) {
					return 'real';
				}

				protected function typeDouble(\Illuminate\Support\Fluent $column) {
					return 'real';
				}

				protected function typeBoolean(\Illuminate\Support\Fluent $column) {
					return 'integer';
				}

				protected function typeDecimal(\Illuminate\Support\Fluent $column) {
					return 'text';
				}
			};
			// Querying non-existing columns throws no error when escaped with ",
			// so we switch to ` instead, which brings back errors
			$strictQuery = new class () extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
				protected function wrapValue($value) {
					return $value === '*' ? $value : '`' . str_replace('`', '``', $value) . '`';
				}
			};
			if (isset(BotRunner::$arguments['strict'])) {
				if (version_compare($sqliteVersion, "3.37.0", ">=")) {
					$this->capsule->getConnection()->setSchemaGrammar($strictGrammar);
				}
				$this->capsule->getConnection()->setQueryGrammar($strictQuery);
			}
		} elseif ($this->type === DB\Type::PostgreSQL) {
			do {
				$this->sql = null;
				try {
					$this->capsule->addConnection([
						'driver' => 'pgsql',
						'host' => $config->host,
						'database' => $config->name,
						'username' => $config->username,
						'password' => $config->password,
						'charset' => 'utf8',
						'collation' => 'utf8_unicode_ci',
						'prefix' => '',
					]);
					$this->sql = $this->capsule->getConnection()->getPdo();
				} catch (PDOException $e) {
					if (!$errorShown) {
						$this->logger->error(
							"Cannot connect to the PostgreSQL db at {$config->host}: ".
							trim($e->errorInfo[2] ?? $e->getMessage())
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
		} elseif ($this->type === DB\Type::MSSQL) {
			do {
				$this->sql = null;
				try {
					$this->capsule->addConnection([
						'driver' => 'sqlsrv',
						'host' => $config->host,
						'database' => $config->name,
						'username' => $config->username,
						'password' => $config->password,
						'charset' => 'utf8',
						'collation' => 'utf8_unicode_ci',
						'prefix' => '',
					]);
					$this->sql = $this->capsule->getConnection()->getPdo();
				} catch (PDOException $e) {
					if (!$errorShown) {
						$e->errorInfo ??= [$e->getCode(), $e->getCode(), $e->getMessage()];
						$this->logger->error(
							"Cannot connect to the MSSQL db at {$config->host}: ".
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
			throw new Exception(
				"Invalid database type: '{$config->type->value}'.  Expecting '".
				DB\Type::MySQL->value . "', '". DB\Type::PostgreSQL->value.
				"' or '" . DB\Type::SQLite->value . "'."
			);
		}
		$this->capsule->setAsGlobal();
		$this->capsule->setFetchMode(PDO::FETCH_CLASS);
		$this->capsule->getConnection()->beforeExecuting(
			function (string $query, array $bindings, Connection $connection): void {
				if (!isset($this->sql)) {
					return;
				}
				$this->logger->debug(
					$query,
					[
						"params" => $bindings,
						"driver" => $this->sql->getAttribute(PDO::ATTR_DRIVER_NAME),
						"version" => $this->sql->getAttribute(PDO::ATTR_SERVER_VERSION),
					]
				);
			}
		);
	}

	/** Get the configured database type */
	public function getType(): DB\Type {
		return $this->type;
	}

	/** Change the SQL to work in a variety of MySQL/SQLite versions */
	public function applySQLCompatFixes(string $sql): string {
		if (!empty($this->sqlReplacements)) {
			$search = array_keys($this->sqlReplacements);
			$replace = array_values($this->sqlReplacements);
			$sql = str_ireplace($search, $replace, $sql);
		}
		foreach ($this->sqlRegexpReplacements as $search => $replace) {
			$sql = Safe::pregReplace($search, $replace, $sql);
			assert(is_string($sql));
		}
		return $sql;
	}

	/** Start a transaction */
	public function beginTransaction(): void {
		$this->logger->info("Starting transaction");
		$this->sql?->beginTransaction();
	}

	/** Start a transaction */
	public function awaitBeginTransaction(): void {
		$start = microtime(true);
		$notified = false;
		while ($this->inTransaction()) {
			$duration = microtime(true) - $start;
			if ($duration > 2 && !$notified) {
				$this->logger->notice("Waiting for beginning a transaction for over {duration}s.", [
					"duration" => 2,
				]);
				$notified = true;
			}
			delay(0.01);
		}
		$this->beginTransaction();
	}

	/** Commit a transaction */
	public function commit(): void {
		$this->logger->info("Committing transaction");
		try {
			$this->sql?->commit();
		} catch (PDOException $e) {
			$this->logger->info("No active transaction to commit");
		}
	}

	/** Roll back a transaction */
	public function rollback(): void {
		$this->logger->info("Rolling back transaction");
		$this->sql?->rollback();
	}

	/** Check if we're currently in a transaction */
	public function inTransaction(): bool {
		return $this->sql?->inTransaction() ?? false;
	}

	/** Format SQL code by replacing placeholders like <myname> */
	public function formatSql(string $sql): string {
		$sql = preg_replace_callback(
			"/<table:(.+?)>/",
			function (array $matches): string {
				return $this->tableNames[$matches[1]] ?? $matches[0];
			},
			$sql
		);
		$sql = str_replace("<myname>", $this->getBotname(), $sql);

		return $sql;
	}

	/** Insert a DBRow $row into the database table $table */
	public function insert(string $table, DBRow $row, ?string $sequence=""): int {
		$refClass = new ReflectionClass($row);
		$props = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		$data = [];
		foreach ($props as $prop) {
			if (count($prop->getAttributes(NCA\DB\Ignore::class))) {
				continue;
			}
			if (count($prop->getAttributes(NCA\DB\AutoInc::class))) {
				continue;
			}
			if (!$prop->isInitialized($row)) {
				continue;
			}
			$data[$prop->name] = $prop->getValue($row);
			if (count($attrs = $prop->getAttributes(NCA\DB\MapWrite::class))) {
				/** @var NCA\DB\MapWrite */
				$mapper = $attrs[0]->newInstance();
				$data[$prop->name] = $mapper->map($data[$prop->name]);
			} elseif ($data[$prop->name] instanceof DateTime) {
				$data[$prop->name] = $data[$prop->name]->getTimestamp();
			} elseif ($data[$prop->name] instanceof BackedEnum) {
				$data[$prop->name] = $data[$prop->name]->value;
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
	 * @param string          $table Name of the database table
	 * @param string|string[] $key   Name of the primary key or array of the primary keys
	 * @param DBRow           $row   The data to update
	 *
	 * @return int Number of updates records
	 */
	public function update(string $table, string|array $key, DBRow $row): int {
		$refClass = new ReflectionClass($row);
		$props = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		$updates = [];
		foreach ($props as $prop) {
			if (count($prop->getAttributes(NCA\DB\Ignore::class))) {
				continue;
			}
			if (!$prop->isInitialized($row)) {
				continue;
			}
			$updates[$prop->name] = $prop->getValue($row);
			if (count($attrs = $prop->getAttributes(NCA\DB\MapWrite::class))) {
				/** @var NCA\DB\MapWrite */
				$mapper = $attrs[0]->newInstance();
				$updates[$prop->name] = $mapper->map($updates[$prop->name]);
			} elseif ($updates[$prop->name] instanceof DateTime) {
				$updates[$prop->name] = $updates[$prop->name]->getTimestamp();
			} elseif ($updates[$prop->name] instanceof BackedEnum) {
				$updates[$prop->name] = $updates[$prop->name]->value;
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

	/** Get a schema builder instance. */
	public function schema(?string $connection=null): SchemaBuilder {
		$schema = $this->capsule->schema($connection);
		$logger = new LoggerWrapper("Core/QueryBuilder");
		Registry::injectDependencies($logger);
		$builder = new SchemaBuilder($schema, $this);
		return $builder;
	}

	/**
	 * Get a fluent query builder instance.
	 *
	 * @param \Closure|\Illuminate\Database\Query\Builder|string $table
	 */
	public function table($table, ?string $as=null, ?string $connection=null): QueryBuilder {
		if (is_string($table)) {
			$table = $this->formatSql($table);
		}
		$builder = $this->capsule->table($table, $as, $connection);
		$myBuilder = new QueryBuilder($builder->getConnection(), $builder->getGrammar(), $builder->getProcessor());
		Registry::injectDependencies($myBuilder);
		foreach (get_object_vars($builder) as $attr => $value) {
			$myBuilder->{$attr} = $value;
		}
		return $myBuilder;
	}

	/**
	 * Makes "from" fetch from a subquery.
	 *
	 * @param \Closure|\Illuminate\Database\Query\Builder|string $query
	 */
	public function fromSub($query, string $as): QueryBuilder {
		$query = $this->capsule->getConnection()->query()->fromSub($query, $as);
		$builder = new QueryBuilder($query->connection, $query->grammar, $query->processor);
		Registry::injectDependencies($builder);
		foreach (get_object_vars($query) as $attr => $value) {
			$builder->{$attr} = $value;
		}
		return $builder;
	}

	public function createDatabaseSchema(): void {
		$instances = Registry::getAllInstances();
		$migrations = new Collection();
		foreach ($instances as $instance) {
			$migrations = $migrations->merge($this->getMigrationFiles($instance));
		}
		$this->runMigrations(...$migrations->toArray());
	}

	public function createMigrationTables(): void {
		foreach (["migrations", "migrations_<myname>"] as $table) {
			if ($this->schema()->hasTable($table)) {
				continue;
			}
			$this->schema()->create($table, function (Blueprint $table): void {
				$table->id();
				$table->string('module');
				$table->string('migration');
				$table->integer('applied_at');
			});
		}
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

	public function runMigrations(CoreMigration ...$migrations): void {
		$migrations = new Collection($migrations);
		$this->createMigrationTables();
		$groupedMigs = $migrations->groupBy("module");
		$missingMigs = $groupedMigs->map(function (Collection $migs, string $module): Collection {
			return $this->filterAppliedMigrations($module, $migs);
		})->flatten()
			->sort(function (CoreMigration $f1, CoreMigration $f2): int {
				return $f1->order <=> $f2->order;
			});
		if ($missingMigs->isEmpty()) {
			return;
		}
		$start = microtime(true);
		$this->logger->notice("Applying {numMigs} database migrations", [
			"numMigs" => $missingMigs->count(),
		]);
		foreach ($missingMigs as $mig) {
			try {
				$this->beginTransaction();
				$this->applyMigration($mig);
				if ($this->inTransaction()) {
					$this->commit();
				}
			} catch (Throwable $e) {
				$this->logger->critical(
					"Error applying migration {module}/{baseName}: {error}",
					array_merge((array)$mig, ["error" => $e->getMessage(), "exception" => $e])
				);
				if ($this->inTransaction()) {
					$this->rollback();
				}
				exit(1);
			}
		}
		$end = microtime(true);
		$this->logger->notice("All migrations applied successfully in {timeMS}ms", [
			"timeMS" => number_format(($end - $start) * 1000, 2),
		]);
		EventLoop::run();
	}

	/**
	 * Load a CSV file $file into table $table
	 *
	 * @param string $module The module to which this file belongs
	 * @param string $file   The full path to the CSV file
	 *
	 * @return bool true if inserted, false if already up-to-date
	 *
	 * @throws Exception
	 */
	public function loadCSVFile(string $module, string $file): bool {
		$fileBase = pathinfo($file, PATHINFO_FILENAME);
		$table = $fileBase;
		if (!$this->fs->exists($file)) {
			throw new Exception("The CSV-file {$file} was not found.");
		}
		$version = $this->fs->getModificationTime($file) ?: 0;
		$handle = $this->fs->openFile($file, 'r');
		foreach (splitLines($handle) as $line) {
			if (substr($line, 0, 1) !== "#") {
				break;
			}
			$line = trim($line);
			if (!count($matches = Safe::pregMatch("/^#\s*(.+?):\s*(.+)$/i", $line))) {
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
		$handle->close();
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
			description: "DB version of {$fileBase}",
			mode: 'noedit',
			type: (is_int($version) || preg_match('/^\d+$/', $version)) ? 'timestamp' : 'text',
			value: "0"
		);

		if ($this->table($table)->exists() && $this->util->compareVersionNumbers((string)$version, (string)$currentVersion) <= 0) {
			$msg = "'{$table}' database already up to date! version: '{$currentVersion}'";
			$this->logger->info($msg);
			return false;
		}
		$this->logger->info("Inserting {file}", ["file" => $file]);
		$csv = new Reader($file, $this->fs);
		$items = [];
		$itemCount = 0;
		try {
			if (isset($where) && is_countable($where) && count($where)) {
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
	 * @param string   $column The table column to test against
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

	/**
	 * Get a list of all DB migrations that were already applied in $module
	 *
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

	/** @return Collection<CoreMigration> */
	private function getMigrationFiles(object $instance): Collection {
		$migrations = new Collection();
		$ref = new ReflectionClass($instance);
		$attrs = $ref->getAttributes(NCA\HasMigrations::class);
		if (empty($attrs)) {
			return $migrations;
		}

		$migDir = $attrs[0]->newInstance();
		$migDir->module ??= is_subclass_of($instance, ModuleInstanceInterface::class) ? $instance->getModuleName() : null;
		if (!isset($migDir->module)) {
			return new Collection();
		}
		$fullFile = $ref->getFileName();
		if (!is_string($fullFile)) {
			return new Collection();
		}
		$fullDir = rtrim(dirname($fullFile) . DIRECTORY_SEPARATOR . $migDir->dir, DIRECTORY_SEPARATOR);
		foreach (get_declared_classes() as $class) {
			if (!in_array(SchemaMigration::class, class_implements($class))) {
				continue;
			}
			$refClass = new ReflectionClass($class);
			$fileName = $refClass->getFileName();
			if ($fileName === false) {
				continue;
			}
			if (!str_starts_with($fileName, $fullDir . DIRECTORY_SEPARATOR)) {
				continue;
			}
			$migAttr = $refClass->getAttributes(AttributesMigration::class);
			if (count($migAttr) !== 1) {
				continue;
			}
			$classTokens = explode("\\", $class);
			$attrMigration = $migAttr[0]->newInstance();
			$baseName = $attrMigration->order . "_".
				$classTokens[count($classTokens)-1].
				($attrMigration->shared ? ".shared" : "");
			$migrations->push(new CoreMigration(
				filePath: $fileName,
				baseName: $baseName,
				className: $class,
				order: $attrMigration->order,
				module: $migDir->module,
				shared: $attrMigration->shared,
			));
		}
		return $migrations;
	}

	/**
	 * @param Collection<CoreMigration> $migrations
	 *
	 * @return Collection<CoreMigration>
	 */
	private function filterAppliedMigrations(string $module, Collection $migrations): Collection {
		$applied = $this->getAppliedMigrations($module);
		return $migrations->filter(function (CoreMigration $m) use ($applied): bool {
			return !$applied->contains("migration", $m->baseName);
		});
	}

	private function applyMigration(CoreMigration $mig): void {
		$table = $this->formatSql($mig->shared ? "migrations" : "migrations_<myname>");
		$class = $mig->className;
		$obj = new $class();
		if (!($obj instanceof SchemaMigration)) {
			return;
		}
		Registry::injectDependencies($obj);
		try {
			$this->logger->info("Running migration {migration}", [
				"migration" => $class,
			]);
			$obj->migrate($this->logger, $this);
		} catch (Throwable $e) {
			if (isset(BotRunner::$arguments["migration-errors-fatal"])) {
				throw $e;
			}
			$this->logger->error(
				"Error executing {$class}::migrate(): " .
				$e->getMessage(),
				["exception" => $e]
			);
			return;
		}
		$this->table($table)->insert([
			'module' => $mig->module,
			'migration' => $mig->baseName,
			'applied_at' => time(),
		]);
	}
}
