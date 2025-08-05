<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\{Arr, Collection};
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB\DBType,
	Exceptions\SQLException,
};
use PDOException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Safe\{DateTime, DateTimeImmutable};
use Throwable;

/** This is an extended SQL query builder */
class QueryBuilder extends Builder {
	private const CLASS_SEP = '⚡️';

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private DB $nadyDB;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Logger]
	private LoggerInterface $logger;

	/**
	 * Return the result of the query as a collection of objects of the given class
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $class The class to parse the rows into
	 *
	 * @return Collection<int,T>
	 */
	public function asObj(string $class): Collection {
		try {
			/** @var Collection<int,T> */
			return $this->fetchAll($class);
		} catch (SQLException $e) {
			$errorInfo = [''];
			$previous = $e->getPrevious();
			if (isset($previous) && $previous instanceof PDOException) {
				$errorInfo = $previous->errorInfo ?? [''];
			}
			if ($errorInfo[0] === '22003') { // Numeric value out of range
				$this->logger->notice('{message}', [
					'message' => str_replace('ERROR:  ', '', $e->getMessage()),
					'exception' => $e,
				]);
				return Collection::make([]);
			}
			throw $e;
		}
	}

	/** Create a new instance based on the base illuminate builder */
	public static function fromBuilder(Builder $builder): self {
		$instance = new self(
			$builder->getConnection(),
			$builder->getGrammar(),
			$builder->getProcessor()
		);
		foreach (get_object_vars($builder) as $attr => $value) {
			$instance->{$attr} = $value;
		}
		Registry::injectDependencies($instance);
		return $instance;
	}

	/**
	 * Return the first result row as an object of the given class
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $class The class to parse the row into
	 *
	 * @return ?T `null` if the result is empty
	 */
	public function firstObj(string $class): ?object {
		return $this->limit(1)->asObj($class)->first();
	}

	/**
	 * Return the result of the query as a list of objects of the given class
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $class The class to parse the rows into
	 *
	 * @return T[]
	 *
	 * @psalm-return list<T>
	 */
	public function asObjArr(string $class): array {
		/** @var Collection<int,T> */
		$result = $this->fetchAll($class);
		return $result->toList();
	}

	/**
	 * Pluck values of a given column as strings
	 *
	 * @param string $column Name of the column
	 *
	 * @return Collection<int,string>
	 */
	public function pluckStrings(string $column): Collection {
		/** @var Collection<int,mixed> */
		$colValues = $this->pluck($column);
		return $colValues->map(static function (mixed $value, int $key): string {
			return (string)$value;
		});
	}

	/**
	 * Pluck values of a given column as integers
	 *
	 * @param string $column Name of the column
	 *
	 * @return Collection<int,int>
	 */
	public function pluckInts(string $column): Collection {
		/** @var Collection<int,mixed> */
		$colValues = $this->pluck($column);
		return $colValues->map(static function (mixed $value, int $key): int {
			return (int)$value;
		});
	}

	/**
	 * Escape and use a table name as `as` and prepend this
	 *
	 * @param string $as The name to cast to
	 */
	public function as(string $as): string {
		return ' as ' . $this->grammar->wrap($as);
	}

	/**
	 * Order by a function (`LENGTH`, etc.)
	 *
	 * @param string $function  Name of the SQL function
	 * @param mixed  $param     The parameter to the function, for example the column name
	 * @param string $direction The sort direction (`'asc'`, `'desc'`)
	 */
	public function orderByFunc(string $function, mixed $param, string $direction='asc'): self {
		$function = $this->dbFunc($function);
		return $this->orderByRaw(
			"{$function}({$param}) {$direction}"
		);
	}

	/**
	 * Order the results by calling a function on the column of each row
	 *
	 * @param string $function  The name of the function to call (e.g. `LENGTH`)
	 * @param mixed  $column    The column to sort on
	 * @param string $direction The sort direction (`'asc'`, `'desc'`)
	 */
	public function orderByColFunc(string $function, mixed $column, string $direction='asc'): self {
		$function = $this->dbFunc($function);
		if (!is_array($column)) {
			$column = [$column];
		}

		/** @var string[] $column */
		$column = array_map($this->grammar->wrap(...), $column);
		$cols = implode(', ', $column);
		return $this->orderByRaw(
			"{$function}({$cols}) {$direction}"
		);
	}

	/**
	 * Return the SQL code for a call to a function
	 *
	 * @param string      $function Name of the function to call
	 * @param mixed       $column   The column name to pass as argument to the function
	 * @param null|string $as       If set, cast the function result into a name
	 *
	 * @return string The SQL string
	 */
	public function colFunc(string $function, mixed $column, ?string $as=null): string {
		$function = $this->dbFunc($function);
		if (!is_array($column)) {
			$column = [$column];
		}

		/** @var string[] $column */
		$column = array_map($this->grammar->wrap(...), $column);
		$cols = implode(', ', $column);
		return "{$function}({$cols})".
			(isset($as) ? ' AS ' . $this->grammar->wrap($as) : '');
	}

	/**
	 * Return the SQL code for a call to a function with arbitrary parameter
	 *
	 * @param string      $function Name of the function to call
	 * @param mixed       $param    The already escaped argument to the function
	 * @param null|string $as       If set, cast the function result into a name
	 *
	 * @return string The SQL string
	 */
	public function rawFunc(string $function, mixed $param, ?string $as=null): string {
		$function = $this->dbFunc($function);
		return
			"{$function}({$param})".
			(isset($as) ? ' AS ' . $this->grammar->wrap($as) : '');
	}

	/** Add an "or where ilike" clause to the query. */
	public function orWhereIlike(string $column, string $value): self {
		return $this->orWhere($this->raw($this->colFunc('LOWER', $column)), 'like', strtolower($value));
	}

	/** Add an "where ilike" clause to the query. */
	public function whereIlike(string $column, string $value, string $boolean='and'): self {
		return $this->where($this->raw($this->colFunc('LOWER', $column)), 'like', strtolower($value), $boolean);
	}

	/** {@inheritDoc} */
	public function join($table, $first, $operator=null, $second=null, $type='inner', $where=false): self {
		if (is_string($table)) {
			$table = $this->nadyDB->formatSql($table);
		}
		return parent::join($table, $first, $operator, $second, $type);
	}

	/** {@inheritDoc} */
	public function crossJoin($table, $first=null, $operator=null, $second=null): self {
		assert(is_string($table));
		return parent::crossJoin($this->nadyDB->formatSql($table), $first, $operator, $second);
	}

	/** {@inheritDoc} */
	public function newQuery(): self {
		$instance = new self($this->connection, $this->grammar, $this->processor);
		$instance->nadyDB = $this->nadyDB;
		return $instance;
	}

	/**
	 * Insert more than 1 entry into the database
	 *
	 * Depending on the DB system, there is a limit of maximum
	 * rows or placeholders that we can insert.
	 *
	 * @param array<string,mixed>|list<array<string,mixed>> $values
	 */
	public function chunkInsert(array $values): bool {
		if (!array_is_list($values)) {
			return $this->insert($values);
		}
		if (!count($values) || !is_array($values[0])) {
			return true;
		}
		$chunkSize = (int)floor($this->nadyDB->maxPlaceholders / count($values[0]));
		$result = true;
		while (count($values)) {
			$chunk = array_splice($values, 0, $chunkSize);
			$result = $result && $this->insert($chunk);
		}
		return $result;
	}

	/**
	 * Upsert more than 1 entry into the database
	 *
	 * Depending on the DB system, there is a limit of maximum
	 * rows or placeholders that we can insert.
	 *
	 * @param array<string,mixed>|list<array<string,mixed>> $values
	 * @param string|list<string>                           $uniqueBy
	 * @param ?list<string>                                 $update
	 */
	public function chunkUpsert(array $values, array|string $uniqueBy, ?array $update=null): int {
		if (!array_is_list($values)) {
			return $this->upsert($values, $uniqueBy, $update);
		}
		if (!count($values)) {
			return 0;
		}
		if (!is_array($values[0])) {
			return 0;
		}
		$chunkSize = (int)floor($this->nadyDB->maxPlaceholders / count($values[0]));
		$result = 0;
		while (count($values)) {
			$chunk = array_splice($values, 0, $chunkSize);
			$result += $this->upsert($chunk, $uniqueBy, $update);
		}
		return $result;
	}

	/**
	 * Get the raw SQL representation of the update query with embedded bindings.
	 *
	 * @param array<string,scalar> $values
	 */
	public function toUpdateSql(array $values): string {
		$this->applyBeforeQueryCallbacks();
		return $this->grammar->compileUpdate($this, $values);
	}

	/**
	 * Get the raw SQL representation of the insert query with embedded bindings.
	 *
	 * @param array<string,scalar> $values
	 */
	public function toInsertSql(array $values): string {
		$this->applyBeforeQueryCallbacks();
		return $this->grammar->compileInsert($this, $values);
	}

	/**
	 * Get the raw SQL representation of the update query with substituted bindings.
	 *
	 * @param array<string,scalar> $values
	 */
	public function toRawUpdateSql(array $values): string {
		$sql = $this->toUpdateSql($values);

		return $this->grammar->substituteBindingsIntoRawSql(
			$sql,
			$this->cleanBindings(
				$this->grammar->prepareBindingsForUpdate($this->bindings, $values)
			),
		);
	}

	/**
	 * Get the raw SQL representation of the insert query with substituted bindings.
	 *
	 * @param array<string,scalar> $values
	 */
	public function toRawInsertSql(array $values): string {
		$sql = $this->toInsertSql($values);

		return $this->grammar->substituteBindingsIntoRawSql(
			$sql,
			$this->cleanBindings(Arr::flatten($values, 1))
		);
	}

	/** get the name of the variable type, or `null` if none, or more than one */
	protected function guessVarTypeFromReflection(ReflectionParameter $refParam): ?string {
		$refType = $refParam->getType();
		if ($refType instanceof ReflectionNamedType) {
			return $refType->getName();
		}
		return null;
	}

	/** Return the escaped name of a DB function */
	protected function dbFunc(string $function): string {
		$type = $this->nadyDB->getType();
		switch (strtolower($function)) {
			case 'length':
				if ($type === DBType::MySQL) {
					return 'length';
				}
				break;
			default:
				return $function;
		}
		return $function;
	}

	/**
	 * Compile a hydrator that creates instances of a given class from a DB result row
	 *
	 * @param class-string $className Name of the class for which to compile the hydrator
	 */
	private function compileForClass(string $className): void {
		$cacheLines = [];
		$colMappings = [];
		$refClass = new ReflectionClass($className);
		foreach ($refClass->getProperties() as $refProperty) {
			$colMapping = $refProperty->getAttributes(NCA\DB\ColName::class);
			if (count($colMapping)) {
				// $colMappings[$colMapping[0]->newInstance()->col] = $refProperty->getName();
				$colMappings[$refProperty->getName()] = $colMapping[0]->newInstance()->col;
			}
		}
		$refConstr = $refClass->getConstructor();
		if (!isset($refConstr)) {
			throw new Exception("{$className} has no constructor.");
		}
		foreach ($refConstr->getParameters() as $refParam) {
			$paramName = $refParam->getName();
			$colName = $colMappings[$paramName] ?? $paramName;
			try {
				$type = $this->guessVarTypeFromReflection($refParam);
				$defaultValue = null;
				if ($refParam->isOptional()) {
					$defaultValue = var_export($refParam->getDefaultValue(), true);
				}
				$refProp = $refClass->hasProperty($paramName) ? $refClass->getProperty($paramName) : null;
				$readMap = isset($refProp) ? $refProp->getAttributes(NCA\DB\MapRead::class) : [];
				if (count($readMap)) {
					foreach ($readMap as $mapper) {
						$cacheLines []= "{$paramName}: unserialize(" . var_export(serialize($mapper->newInstance()), true) . ')->map($data->' . $colName . '),';
					}
				} else {
					if ($type === 'bool') {
						$cacheLine = "(bool)\$data->{$colName}";
					} elseif ($type === 'int') {
						$cacheLine = "(int)\$data->{$colName}";
					} elseif ($type === 'float') {
						$cacheLine = "(float)\$data->{$colName}";
					} elseif ($type === \DateTime::class || $type === DateTime::class) {
						$cacheLine = "(new \\Safe\\DateTime())->setTimestamp((int)\$data->{$colName})";
					} elseif ($type === \DateTimeImmutable::class || $type === DateTimeImmutable::class) {
						$cacheLine = "(new \\Safe\\DateTimeImmutable())->setTimestamp((int)\$data->{$colName})";
					} elseif ($type === \DateTimeInterface::class) {
						$cacheLine = "(new \\Safe\\DateTimeImmutable())->setTimestamp((int)\$data->{$colName})";
					} elseif ($type === UuidInterface::class) {
						$cacheLine = '\\' . Uuid::class . "::fromString(\$data->{$colName})";
					} elseif (is_a($type, \BackedEnum::class, true)) {
						$cacheLine = "\\{$type}::from(\$data->{$colName})";
					} else {
						$cacheLine = "\$data->{$colName}";
					}
					if (isset($defaultValue) || $refParam->allowsNull()) {
						$defaultValue ??= 'NULL';
						$cacheLines []= "{$paramName}: property_exists(\$data, " . var_export($colName, true) . ") ? (isset(\$data->{$colName}) ? {$cacheLine} : null) : {$defaultValue},";
					} else {
						$cacheLines []= "{$paramName}: {$cacheLine},";
					}
				}
			} catch (Throwable $e) {
				$this->logger->error('{error}', [
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
				throw $e;
			}
		}
		$this->compileCache($className, $cacheLines);
	}

	/**
	 * Create and save the hydrator for a given class into a file and load it
	 *
	 * @param class-string $className  Full name of the class this is a hydrator for
	 * @param list<string> $cacheLines The individual lines that make up the constructor call
	 */
	private function compileCache(string $className, array $cacheLines): void {
		$nsParts = explode('\\', $className);
		$classShortName = array_pop($nsParts);
		$nameSpace = implode('\\', $nsParts);
		$code = '<?php declare(strict_types=1);' . \PHP_EOL.
			\PHP_EOL.
			"namespace {$nameSpace};" . \PHP_EOL.
			\PHP_EOL.
			"class {$classShortName}" . self::CLASS_SEP . 'compiler {' . \PHP_EOL.
			"\tpublic static function fromDB(\\stdClass \$data): {$classShortName} {" . \PHP_EOL.
			"\t\treturn new {$classShortName}(" . \PHP_EOL.
			"\t\t\t". implode(\PHP_EOL . "\t\t\t", $cacheLines) . \PHP_EOL.
			"\t\t);" . \PHP_EOL.
			"\t}" . \PHP_EOL.
			'}' . \PHP_EOL;
		$fileName = $this->fs->tempnam($this->config->paths->cache . \DIRECTORY_SEPARATOR, 'db_');
		$this->fs->write($fileName, $code);
		try {
			// Sometimes, the same class is requested multiple times async
			if (!class_exists($className . self::CLASS_SEP . 'compiler', false)) {
				require_once $fileName;
			}
		} finally {
			$this->fs->deleteFile($fileName);
		}
	}

	/**
	 * Execute an SQL statement and return all rows as an array of objects of the given class
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $className
	 *
	 * @return Collection<int,T>
	 */
	private function fetchAll(string $className): Collection {
		$cacheClass = "{$className}" . self::CLASS_SEP . 'compiler';

		try {
			$data = $this->get();
		} catch (QueryException $e) {
			throw new SQLException(message: $e->getMessage(), previous: $e);
		}
		if ($data->isEmpty()) {
			/** @var Collection<int,T> $data */
			return $data;
		}

		/** @var Collection<int,\stdClass> $data */
		if (!class_exists($cacheClass, false)) {
			$this->compileForClass($className);
		}
		if (class_exists($cacheClass, false)) {
			/** @psalm-suppress MixedMethodCall */
			$compiler = $cacheClass::fromDB(...);

			/** @psalm-suppress MixedArgument */
			$result = $data->map($compiler);

			/** @var Collection<int,T> $result */
			return $result;
		}
		throw new \Exception("Unable to infer a database mapper for {$className}");
	}
}
