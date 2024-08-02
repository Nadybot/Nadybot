<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\ByteStream\splitLines;
use function Amp\delay;
use function Safe\{class_implements, preg_match};

use Amp\File\FilesystemException;
use BackedEnum;
use DateTimeInterface;
use Exception;
use Illuminate\Database\{
	Capsule\Manager as Capsule,
	Connection,
	Schema\Blueprint,
};
use Illuminate\Support\{Collection, Fluent};
use InvalidArgumentException;
use Nadybot\Core\Attributes\Migration as AttributesMigration;
use Nadybot\Core\{
	Attributes as NCA,
	CSV\Reader,
	Config\BotConfig,
	DBSchema\Migration,
	Migration as CoreMigration,
	Types\ModuleInstanceInterface,
	Types\SettingMode,
};
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};
use ReflectionClass;
use ReflectionProperty;
use Revolt\EventLoop;
use Safe\DateTimeImmutable;
use Throwable;

#[NCA\Instance]
#[NCA\HasMigrations(module: 'Core')]
class DB {
	public const SQLITE_MIN_VERSION = '3.24.0';

	public const MYSQL = 'mysql';
	public const SQLITE = 'sqlite';
	public const POSTGRESQL = 'postgresql';
	public const MSSQL = 'mssql';

	public int $maxPlaceholders = 9_000;

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

	private ?string $transactionOpened = null;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private SettingManager $settingManager;

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
			throw new Exception('You are not connected to any database.');
		}
		$version = $this->sql->getAttribute(PDO::ATTR_SERVER_VERSION);
		if (!isset($version) || !is_string($version)) {
			throw new Exception('Your database is not supported');
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

		$errorShown = match ($this->type) {
			DB\Type::MySQL => $this->initMySQL($errorShown),
			DB\Type::SQLite => $this->initSQLite($errorShown),
			DB\Type::PostgreSQL => $this->initPostgreSQL($errorShown),
			DB\Type::MSSQL => $this->initMSSQL($errorShown),
		};
		$this->capsule->setAsGlobal();
		$this->capsule->setFetchMode(PDO::FETCH_CLASS);
		$this->capsule->getConnection()->beforeExecuting(
			function (string $query, array $bindings, Connection $connection): void {
				if (!isset($this->sql)) {
					return;
				}
				$this->logger->debug('{query}', [
					'query' => $query,
					'params' => $bindings,
					'driver' => $this->sql->getAttribute(PDO::ATTR_DRIVER_NAME),
					'version' => $this->sql->getAttribute(PDO::ATTR_SERVER_VERSION),
				]);
			}
		);
	}

	/** Get the configured database type */
	public function getType(): DB\Type {
		return $this->type;
	}

	/** Change the SQL to work in a variety of MySQL/SQLite versions */
	public function applySQLCompatFixes(string $sql): string {
		if (count($this->sqlReplacements) > 0) {
			$search = array_keys($this->sqlReplacements);
			$replace = array_values($this->sqlReplacements);
			$sql = str_ireplace($search, $replace, $sql);
		}
		foreach ($this->sqlRegexpReplacements as $search => $replace) {
			$sql = Safe::pregReplace($search, $replace, $sql);
		}
		return $sql;
	}

	/** Start a transaction */
	public function beginTransaction(): void {
		$bt = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
		foreach ($bt as $trace) {
			if (isset($trace['file']) && ($trace['file'] === __FILE__)) {
				continue;
			}
			$this->transactionOpened = ($trace['file'] ?? '{closure}').
				'#' . ($trace['line'] ?? '0');
			$this->logger->info('Starting transaction from {file}#{line}', [
				'file' => $trace['file']??null,
				'line' => $trace['line']??null,
			]);
			break;
		}
		$this->sql?->beginTransaction();
	}

	/** Start a transaction */
	public function awaitBeginTransaction(): void {
		$start = microtime(true);
		$notified = false;
		while ($this->inTransaction()) {
			$duration = microtime(true) - $start;
			if ($duration > 2 && !$notified) {
				$this->logCaller('Waiting for beginning a transaction for over 2s.');
				$notified = true;
			}
			delay(0.01);
		}
		$this->beginTransaction();
	}

	/** Commit a transaction */
	public function commit(): void {
		$this->logCaller('Committing transaction');
		$this->logger->info('Committing transaction');
		try {
			$this->sql?->commit();
		} catch (PDOException) {
			$this->logger->info('No active transaction to commit');
		}
		$this->transactionOpened = null;
	}

	/** Roll back a transaction */
	public function rollback(): void {
		$this->logCaller('Rolling back transaction');
		$this->logger->info('Rolling back transaction');
		$this->sql?->rollBack();
		$this->transactionOpened = null;
	}

	/** Check if we're currently in a transaction */
	public function inTransaction(): bool {
		return $this->sql?->inTransaction() ?? false;
	}

	/** Get where the current transaction was opened */
	public function getTransactionOpener(): ?string {
		return $this->transactionOpened;
	}

	/** Format SQL code by replacing placeholders like <myname> */
	public function formatSql(string $sql): string {
		$sql = Safe::pregReplaceCallback(
			'/<table:(.+?)>/',
			function (array $matches): string {
				return $this->tableNames[$matches[1]] ?? $matches[0];
			},
			$sql
		);
		$sql = str_replace('<myname>', $this->getBotname(), $sql);

		return $sql;
	}

	/**
	 * Insert a DBRow $row into the database
	 *
	 * @param DBTable|iterable<array-key,DBTable> $row
	 */
	public function insert(iterable|DBTable $row, ?string $table=null): int {
		if (!($row instanceof DBTable)) {
			$result = 1;
			foreach ($row as $entry) {
				$result *= $this->insert($entry, $table);
			}
			return $result === 0 ? 0 : 1;
		}
		$table ??= $row::tryGetTable();
		if (!isset($table)) {
			throw new InvalidArgumentException(__CLASS__ . '::' . __FUNCTION__ . '(): $table missing');
		}
		$refClass = new ReflectionClass($row);
		$props = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		$data = [];
		$sequence = null;
		$successId = 1;
		foreach ($props as $prop) {
			$colName = $prop->name;
			if (count($colProp = $prop->getAttributes(NCA\DB\ColName::class))) {
				$colName = $colProp[0]->newInstance()->col;
			}
			if (count($prop->getAttributes(NCA\DB\Ignore::class))) {
				continue;
			}
			if (count($prop->getAttributes(NCA\DB\AutoInc::class))) {
				if ($prop->getValue($row) === null) {
					$sequence = $colName;
					continue;
				}
				$successId = $prop->getValue($row);
			}
			if (!$prop->isInitialized($row)) {
				continue;
			}
			$data[$colName] = $prop->getValue($row);
			if (count($attrs = $prop->getAttributes(NCA\DB\MapWrite::class))) {
				$mapper = $attrs[0]->newInstance();
				$data[$colName] = $mapper->map($data[$colName]);
			} elseif ($data[$colName] instanceof DateTimeInterface) {
				$data[$colName] = $data[$colName]->getTimestamp();
			} elseif ($data[$colName] instanceof BackedEnum) {
				$data[$colName] = $data[$colName]->value;
			} elseif ($data[$colName] instanceof UuidInterface) {
				$data[$colName] = $data[$colName]->toString();
			}
		}
		$table = $this->formatSql($table);
		if ($sequence === null) {
			return $this->table($table)->insert($data) ? $successId : 0;
		}
		return $this->table($table)->insertGetId($data, $sequence);
	}

	public function upsert(DBTable $row, ?string $table=null): int {
		$table ??= $row::tryGetTable();
		if (!isset($table)) {
			throw new InvalidArgumentException(__CLASS__ . '::' . __FUNCTION__ . '(): $table missing');
		}
		$refClass = new ReflectionClass($row);
		$props = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);

		/** @var array<string,mixed> */
		$data = [];
		$pks = [];
		foreach ($props as $prop) {
			$colName = $prop->name;
			if (count($colProp = $prop->getAttributes(NCA\DB\ColName::class))) {
				$colName = $colProp[0]->newInstance()->col;
			}
			if (count($prop->getAttributes(NCA\DB\Ignore::class))) {
				continue;
			}
			if (count($prop->getAttributes(NCA\DB\AutoInc::class))) {
				if ($prop->getValue($row) === null) {
					continue;
				}
				$pks []= $colName;
			} elseif (count($prop->getAttributes(NCA\DB\PK::class))) {
				$pks []= $colName;
			}
			if (!$prop->isInitialized($row)) {
				continue;
			}
			$data[$colName] = $prop->getValue($row);
			if (count($attrs = $prop->getAttributes(NCA\DB\MapWrite::class))) {
				/** DB\MapWrite */
				$mapper = $attrs[0]->newInstance();
				$data[$colName] = $mapper->map($data[$colName]);
			} elseif ($data[$colName] instanceof DateTimeInterface) {
				$data[$colName] = $data[$colName]->getTimestamp();
			} elseif ($data[$colName] instanceof BackedEnum) {
				$data[$colName] = $data[$colName]->value;
			} elseif ($data[$colName] instanceof UuidInterface) {
				$data[$colName] = $data[$colName]->toString();
			}
		}
		$table = $this->formatSql($table);
		$update = array_values(array_diff(array_keys($data), $pks));
		return $this->table($table)->upsert($data, $pks, $update);
	}

	/**
	 * Update a DBRow $row in the database table $table, using property $key in the where
	 *
	 * @param DBTable              $row The data to update
	 * @param null|string|string[] $key Name of the primary key or array of the primary keys
	 *
	 * @return int Number of updates records
	 */
	public function update(DBTable $row, null|string|array $key=null): int {
		$table = $row::tryGetTable();
		if ($table === null) {
			throw new InvalidArgumentException(__CLASS__ . '::' . __FUNCTION__ . '(): unable to derive a table to update');
		}
		$refClass = new ReflectionClass($row);
		$props = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		$updates = [];
		$propNames = [];
		$pks = [];
		foreach ($props as $prop) {
			if (count($prop->getAttributes(NCA\DB\Ignore::class))) {
				continue;
			}
			if (!$prop->isInitialized($row)) {
				continue;
			}
			$colName = $prop->name;
			if (count($colNameProp = $prop->getAttributes(NCA\DB\ColName::class))) {
				$colName = $colNameProp[0]->newInstance()->col;
			}
			if (count($prop->getAttributes(NCA\DB\AutoInc::class))) {
				$pks []= $colName;
			} elseif (count($prop->getAttributes(NCA\DB\PK::class))) {
				$pks []= $colName;
			}
			$propNames[$colName] = $prop->name;
			$updates[$colName] = $prop->getValue($row);
			if (count($attrs = $prop->getAttributes(NCA\DB\MapWrite::class))) {
				$mapper = $attrs[0]->newInstance();
				$updates[$colName] = $mapper->map($updates[$colName]);
			} elseif ($updates[$colName] instanceof DateTimeInterface) {
				$updates[$colName] = $updates[$colName]->getTimestamp();
			} elseif ($updates[$colName] instanceof BackedEnum) {
				$updates[$colName] = $updates[$colName]->value;
			} elseif ($updates[$colName] instanceof UuidInterface) {
				$updates[$colName] = $updates[$colName]->toString();
			}
		}
		$query = $this->table($table);
		$key ??= $pks;
		foreach ((array)$key as $k) {
			$query->where($k, $row->{$propNames[$k]});
		}
		return $query->update($updates);
	}

	/** Register a table name for a key */
	public function registerTableName(string $key, string $table): void {
		$this->tableNames[$key] = $table;
	}

	/** Get a schema builder instance. */
	public function schema(?string $connection=null): SchemaBuilder {
		$schema = $this->capsule::schema($connection);
		$logger = new LoggerWrapper('Core/QueryBuilder');
		Registry::injectDependencies($logger);
		$builder = new SchemaBuilder($schema, $this);
		return $builder;
	}

	/** @return array<int,UuidInterface> */
	public function migrateIdToUuid(string $table, \Closure $callback, string $column='id', ?string $timeColumn=null): array {
		$entries = $this->table($table)->orderBy($column)->get();
		$this->schema()->drop($table);
		$this->schema()->create($table, $callback);

		$result = [];

		/** @return array<string,mixed> */
		$entries = $entries->map(static function (\stdClass $entry) use ($column, $timeColumn, &$result): array {
			$time = null;
			if (isset($timeColumn)) {
				$time = $entry->{$timeColumn} ?? null;
			}
			if (isset($time)) {
				$time = (new DateTimeImmutable())->setTimestamp($time);
			}
			$uuid = Uuid::uuid7($time);
			$result[(int)$entry->{$column}] = $uuid;
			$entry->{$column} = $uuid->toString();
			return (array)$entry;
		})->toList();
		$this->table($table)->chunkInsert($entries);
		return $result;
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
		$builder = $this->capsule::table($table, $as, $connection);
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

		/** @var Collection<int,CoreMigration> */
		$migrations = new Collection();
		foreach ($instances as $instance) {
			$migrations = $migrations->merge($this->getMigrationFiles($instance));
		}
		$this->runMigrations(...$migrations->toArray());
	}

	public function createMigrationTables(): void {
		foreach (['migrations', 'migrations_<myname>'] as $table) {
			$newSchema = static function (Blueprint $table): void {
				$table->uuid('id')->primary();
				$table->string('module');
				$table->string('migration');
				$table->integer('applied_at');
			};
			if ($this->schema()->hasTable($table)) {
				$colType = strtolower($this->schema()->getColumnType($table, 'id'));
				if (str_starts_with($colType, 'int')
					|| str_ends_with($colType, 'int')
					|| str_ends_with($colType, 'integer')
				) {
					$this->migrateIdToUuid($table, $newSchema, 'id', 'applied_at');
				}
				continue;
			}
			$this->schema()->create($table, $newSchema);
		}
	}

	/** Check if a specific migration has already been applied */
	public function hasAppliedMigration(string $module, string $migration): bool {
		return $this->table('migrations_<myname>')
				->where('module', $module)
				->where('migration', $migration)
				->exists()
			|| $this->table('migrations')
				->where('module', $module)
				->where('migration', $migration)
				->exists();
	}

	public function runMigrations(CoreMigration ...$migrations): void {
		$toRun = collect($migrations);
		$this->createMigrationTables();

		/** @var Collection<string,Collection<int,CoreMigration>> */
		$groupedMigs = $toRun->groupBy('module');

		/** @var Collection<int,CoreMigration> */
		$missingMigs = $groupedMigs->map(function (Collection $migs, string $module): Collection {
			return $this->filterAppliedMigrations($module, $migs);
		})->flatten()
			->sort(static function (CoreMigration $f1, CoreMigration $f2): int {
				return $f1->order <=> $f2->order;
			});
		if ($missingMigs->isEmpty()) {
			return;
		}
		$this->logger->info('Missing migrations: {migrations}', ['migrations' => $missingMigs]);
		$start = microtime(true);
		$this->logger->notice('Applying {numMigs} database migrations', [
			'numMigs' => $missingMigs->count(),
		]);
		foreach ($missingMigs as $mig) {
			$this->logger->info('Applying migration: {migration}', ['migration' => $mig]);
			try {
				$this->beginTransaction();
				$this->applyMigration($mig);
				if ($this->inTransaction()) {
					$this->commit();
				}
			} catch (Throwable $e) {
				$this->logger->critical(
					'Error applying migration {module}/{baseName}: {error}',
					array_merge((array)$mig, ['error' => $e->getMessage(), 'exception' => $e])
				);
				if ($this->inTransaction()) {
					$this->rollback();
				}
				throw $e;
			}
		}
		$end = microtime(true);
		$this->logger->notice('All migrations applied successfully in {timeMS}ms', [
			'timeMS' => number_format(($end - $start) * 1_000, 2),
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
		$fileBase = pathinfo($file, \PATHINFO_FILENAME);
		$table = $fileBase;
		if (!$this->fs->exists($file)) {
			throw new Exception("The CSV-file {$file} was not found.");
		}
		$version = $this->fs->getModificationTime($file);
		$handle = $this->fs->openFile($file, 'r');
		$uuidCol = null;
		foreach (splitLines($handle) as $line) {
			if (substr($line, 0, 1) !== '#') {
				break;
			}
			$line = trim($line);
			if (!count($matches = Safe::pregMatch("/^#\s*(.+?):\s*(.+)$/i", $line))) {
				continue;
			}
			$value = $matches[2];
			switch (strtolower($matches[1])) {
				case 'replaces':
					$where = Safe::pregSplit("/\s*=\s*/", $value);
					break;
				case 'version':
					$version = $value;
					break;
				case 'uuid':
					$uuidCol = $value;
					break;
				case 'table':
					$table = $value;
					break;
				case 'requires':
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
			mode: SettingMode::NoEdit,
			type: (is_int($version) || preg_match('/^\d+$/', $version)) ? 'timestamp' : 'text',
			value: '0'
		);

		if ($this->table($table)->exists() && Util::compareVersionNumbers((string)$version, (string)$currentVersion) <= 0) {
			$this->logger->info("'{table}' database already up to date! version: '{current_version}'", [
				'table' => $table,
				'current_version' => $currentVersion,
			]);
			return false;
		}
		$this->logger->info('Inserting {file}', ['file' => $file]);
		$csv = new Reader($file, $this->fs);
		$items = [];
		$itemCount = 0;
		try {
			if (isset($where) && count($where)) {
				$this->table($table)->where(...$where)->delete();
			} else {
				$this->table($table)->delete();
			}
			foreach ($csv->items() as $item) {
				if (isset($uuidCol)) {
					$item[$uuidCol] ??= Uuid::uuid7();
				}
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
			$this->logger->error('{error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			throw $e;
		}
		$this->settingManager->save($settingName, (string)$version);

		if ($version !== 0) {
			$this->logger->info("Updated '{table}' database from '{current_version}' to '{version}'", [
				'table' => $table,
				'current_version' => $currentVersion,
				'version' => $version,
			]);
		} else {
			$this->logger->info("Updated '{table}' database", ['table' => $table]);
		}
		return true;
	}

	/**
	 * Generate an SQL query from a column and a list of criteria
	 *
	 * @param iterable<array-key,string> $params An array of strings that $column must contain (or not contain if they start with "-")
	 * @param string                     $column The table column to test against
	 */
	public function addWhereFromParams(QueryBuilder $query, iterable $params, string $column, string $boolean='and'): void {
		$closure = static function (QueryBuilder $query) use ($params, $column): void {
			foreach ($params as $key => $value) {
				if ($value[0] === '-' && strlen($value) > 1) {
					$value = substr($value, 1);
					$op = 'not like';
				} else {
					$op = 'like';
				}
				$query->whereRaw(
					'LOWER(' . $query->grammar->wrap($column) . ") {$op} ?",
					'%' . strtolower($value) . '%'
				);
			}
		};
		$query->where($closure, null, null, $boolean);
	}

	/**
	 * Get a list of all DB migrations that were already applied in $module
	 *
	 * @return Collection<int,Migration>
	 */
	protected function getAppliedMigrations(string $module): Collection {
		$ownQuery = $this->table('migrations_<myname>')
			->where('module', $module);
		$sharedQuery = $this->table('migrations')
				->where('module', $module);
		return $ownQuery->union($sharedQuery)
			->orderBy('migration')->asObj(Migration::class);
	}

	private function initMySQL(bool $errorShown): bool {
		$config = $this->config->database;
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
					$this->logger->error('Cannot connect to the MySQL db at {db_host}: {error}', [
						'db_host' => $config->host,
						'error' => $e->errorInfo[2],
						'exception' => $e,
					]);
					// @phpstan-ignore-next-line
					$this->logger->notice('Will keep retrying until the db is back up again');
					$errorShown = true;
				}
				sleep(1);
			}
		} while (!isset($this->sql));
		if ($errorShown) {
			$this->logger->notice('Database connection re-established');
		}
		$this->sql->exec("SET sql_mode = 'TRADITIONAL,NO_BACKSLASH_ESCAPES'");
		$this->sql->exec("SET time_zone = '+00:00'");
		$this->sqlCreateReplacements[' AUTOINCREMENT'] = ' AUTO_INCREMENT';
		return $errorShown;
	}

	private function initSQLite(bool $errorShown): bool {
		$config = $this->config->database;
		if ($config->host === '' || $config->host === 'localhost') {
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
					'exists and is writable by the current user.',
					[
						'database' => $dbName,
						'error' => $e->getMessage(),
						'exception' => $e,
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
		if (!isset($sqliteVersion) || version_compare($sqliteVersion, static::SQLITE_MIN_VERSION, '<')) {
			$this->logger->critical(
				'You need at least SQLite {minVersion} for Nadybot. '.
				'Your system is using {version}.',
				[
					'minVersion' => static::SQLITE_MIN_VERSION,
					'version' => $sqliteVersion,
				]
			);
			exit(1);
		}
		$this->sqlCreateReplacements[' AUTO_INCREMENT'] = ' AUTOINCREMENT';
		$this->sqlCreateReplacements[' INT '] = ' INTEGER ';
		$this->sqlCreateReplacements[' INT,'] = ' INTEGER,';
		// SQLite 3.37.0 adds strict tables. These do actual type checking
		$strictGrammar = new class () extends \Illuminate\Database\Schema\Grammars\SQLiteGrammar {
			// @phpstan-ignore-next-line
			public function compileCreate(\Illuminate\Database\Schema\Blueprint $blueprint, \Illuminate\Support\Fluent $command) {
				return parent::compileCreate($blueprint, $command) . ' strict';
			}

			// @phpstan-ignore-next-line
			protected function typeChar(\Illuminate\Support\Fluent $column) {
				return 'text';
			}

			// @phpstan-ignore-next-line
			protected function typeString(\Illuminate\Support\Fluent $column) {
				return 'text';
			}

			// @phpstan-ignore-next-line
			protected function typeFloat(\Illuminate\Support\Fluent $column) {
				return 'real';
			}

			// @phpstan-ignore-next-line
			protected function typeUuid(\Illuminate\Support\Fluent $column) {
				return 'text';
			}

			// @phpstan-ignore-next-line
			protected function typeDouble(\Illuminate\Support\Fluent $column) {
				return 'real';
			}

			// @phpstan-ignore-next-line
			protected function typeBoolean(\Illuminate\Support\Fluent $column) {
				return 'integer';
			}

			// @phpstan-ignore-next-line
			protected function typeDecimal(\Illuminate\Support\Fluent $column) {
				return 'text';
			}
		};
		// Querying non-existing columns throws no error when escaped with ",
		// so we switch to ` instead, which brings back errors
		$strictQuery = new class () extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
			protected function wrapValue($value): string {
				return $value === '*' ? $value : '`' . str_replace('`', '``', $value) . '`';
			}
		};
		if (isset(BotRunner::$arguments['strict'])) {
			if (version_compare($sqliteVersion, '3.37.0', '>=')) {
				$this->capsule->getConnection()->setSchemaGrammar($strictGrammar);
			}
			$this->capsule->getConnection()->setQueryGrammar($strictQuery);
		}
		return $errorShown;
	}

	private function initPostgreSQL(bool $errorShown): bool {
		$config = $this->config->database;
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
						'Cannot connect to the PostgreSQL DB at {db_host}: {error}',
						[
							'db_host' => $config->host,
							'error' => trim($e->errorInfo[2] ?? $e->getMessage()),
							'exception' => $e,
						]
					);
					// @phpstan-ignore-next-line
					$this->logger->notice('Will keep retrying until the db is back up again');
					$errorShown = true;
				}
				sleep(1);
			}
		} while (!isset($this->sql));
		if ($errorShown) {
			$this->logger->notice('Database connection re-established');
		}
		return $errorShown;
	}

	private function initMSSQL(bool $errorShown): bool {
		$config = $this->config->database;
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
						'Cannot connect to the MSSQL DB at {db_host}: {error}',
						[
							'db_host' => $config->host,
							'error' => trim($e->errorInfo[2]),
							'exception' => $e,
						]
					);
					// @phpstan-ignore-next-line
					$this->logger->notice('Will keep retrying until the db is back up again');
					$errorShown = true;
				}
				sleep(1);
			}
		} while (!isset($this->sql));
		if ($errorShown) {
			$this->logger->notice('Database connection re-established');
		}
		return $errorShown;
	}

	private function logCaller(string $logLine): void {
		$bt = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
		foreach ($bt as $trace) {
			if (isset($trace['file']) && ($trace['file'] === __FILE__)) {
				continue;
			}
			$this->logger->info('{log_line} from {file}#{line}', [
				'log_line' => $logLine,
				'file' => $trace['file']??null,
				'line' => $trace['line']??null,
			]);
			break;
		}
	}

	/** @return Collection<int,CoreMigration> */
	private function getMigrationFiles(object $instance): Collection {
		/** @var Collection<int,CoreMigration> */
		$migrations = new Collection();
		$ref = new ReflectionClass($instance);
		$attrs = $ref->getAttributes(NCA\HasMigrations::class);
		if (!count($attrs)) {
			return $migrations;
		}

		$migDir = $attrs[0]->newInstance();
		$migDir->module ??= is_subclass_of($instance, ModuleInstanceInterface::class) ? $instance->getModuleName() : null;
		if (!isset($migDir->module)) {
			return new Collection();
		}
		$fileName = $ref->getFileName();
		if ($fileName === false) {
			return new Collection();
		}
		$fullFile = str_replace('/', \DIRECTORY_SEPARATOR, $fileName);
		$fullDir = rtrim(dirname($fullFile) . \DIRECTORY_SEPARATOR . $migDir->dir, \DIRECTORY_SEPARATOR);
		$fullDir = str_replace('/', \DIRECTORY_SEPARATOR, $fullDir);
		foreach (get_declared_classes() as $class) {
			if (!in_array(SchemaMigration::class, class_implements($class), true)) {
				continue;
			}
			$refClass = new ReflectionClass($class);
			$fileName = $refClass->getFileName();
			if ($fileName === false) {
				continue;
			}
			if (!str_starts_with($fileName, $fullDir . \DIRECTORY_SEPARATOR)) {
				continue;
			}
			$migAttr = $refClass->getAttributes(AttributesMigration::class);
			if (count($migAttr) !== 1) {
				continue;
			}
			$classTokens = explode('\\', $class);
			$attrMigration = $migAttr[0]->newInstance();
			$baseName = $attrMigration->order . '_'.
				$classTokens[count($classTokens)-1].
				($attrMigration->shared ? '.shared' : '');
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
	 * @param Collection<int,CoreMigration> $migrations
	 *
	 * @return Collection<int,CoreMigration>
	 */
	private function filterAppliedMigrations(string $module, Collection $migrations): Collection {
		$applied = $this->getAppliedMigrations($module);
		return $migrations->filter(static function (CoreMigration $m) use ($applied): bool {
			return !$applied->contains('migration', $m->baseName);
		})->flatten();
	}

	private function applyMigration(CoreMigration $mig): void {
		$table = $this->formatSql($mig->shared ? 'migrations' : 'migrations_<myname>');
		$class = $mig->className;
		$obj = new $class();
		if (!($obj instanceof SchemaMigration)) {
			return;
		}
		Registry::injectDependencies($obj);
		try {
			$this->logger->info('Running migration {migration}', [
				'migration' => $class,
			]);
			$obj->migrate($this->logger, $this);
		} catch (Throwable $e) {
			if (isset(BotRunner::$arguments['migration-errors-fatal'])) {
				throw $e;
			}
			$this->logger->error('Error executing {class}::migrate(): {error}', [
				'class' => $class,
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			return;
		}
		$this->table($table)->insert([
			'module' => $mig->module,
			'migration' => $mig->baseName,
			'applied_at' => time(),
			'id' => Uuid::uuid7(),
		]);
	}
}
