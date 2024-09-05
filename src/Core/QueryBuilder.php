<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Attributes\DB\ColName;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Exceptions\SQLException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Safe\{DateTime, DateTimeImmutable};
use stdClass;
use Throwable;

class QueryBuilder extends Builder {
	#[NCA\Inject]
	public BotConfig $config;

	#[NCA\Inject]
	public DB $nadyDB;

	#[NCA\Inject]
	public Filesystem $fs;

	#[NCA\Logger]
	private LoggerInterface $logger;

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $class
	 *
	 * @return Collection<int,T>
	 */
	public function asObj(string $class): Collection {
		try {
			/** @var Collection<int,T> */
			return $this->fetchAll($class);
		} catch (SQLException $e) {
			$errorInfo = $e->getPrevious()?->errorInfo ?? [''];
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

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $class
	 *
	 * @return list<T>
	 */
	public function asObjArr(string $class): array {
		/** @var Collection<int,T> */
		$result = $this->fetchAll($class);
		return $result->toList();
	}

	/**
	 * Pluck values as strings
	 *
	 * @return Collection<int,string>
	 */
	public function pluckStrings(string $column): Collection {
		return $this->pluck($column)
			->map(static function (mixed $value, int $key): string {
				return (string)$value;
			});
	}

	/**
	 * Pluck values as ints
	 *
	 * @return Collection<array-key,int>
	 */
	public function pluckInts(string $column): Collection {
		return $this->pluck($column)
			->map(static function (mixed $value, int $key): int {
				return (int)$value;
			});
	}

	public function as(string $as): string {
		return ' as ' . $this->grammar->wrap($as);
	}

	public function orderByFunc(string $function, mixed $param, string $direction='asc'): self {
		$function = $this->dbFunc($function);
		return $this->orderByRaw(
			"{$function}({$param}) {$direction}"
		);
	}

	public function orderByColFunc(string $function, mixed $column, string $direction='asc'): self {
		$function = $this->dbFunc($function);
		if (!is_array($column)) {
			$column = [$column];
		}
		$column = array_map([$this->grammar, 'wrap'], $column);
		$cols = implode(', ', $column);
		return $this->orderByRaw(
			"{$function}({$cols}) {$direction}"
		);
	}

	public function colFunc(string $function, mixed $column, ?string $as=null): string {
		$function = $this->dbFunc($function);
		if (!is_array($column)) {
			$column = [$column];
		}
		$column = array_map([$this->grammar, 'wrap'], $column);
		$cols = implode(', ', $column);
		return "{$function}({$cols})".
			(isset($as) ? ' AS ' . $this->grammar->wrap($as) : '');
	}

	public function rawFunc(string $function, mixed $param, ?string $as=null): string {
		$function = $this->dbFunc($function);
		return
			"{$function}({$param})".
			(isset($as) ? ' AS ' . $this->grammar->wrap($as) : '');
	}

	public function orWhereIlike(string $column, string $value): self {
		return $this->orWhere($this->raw($this->colFunc('LOWER', $column)), 'like', strtolower($value));
	}

	public function whereIlike(string $column, string $value, string $boolean='and'): self {
		return $this->where($this->raw($this->colFunc('LOWER', $column)), 'like', strtolower($value), $boolean);
	}

	public function join($table, $first, $operator=null, $second=null, $type='inner', $where=false): self {
		if (is_string($table)) {
			$table = $this->nadyDB->formatSql($table);
		}
		return parent::join($table, $first, $operator, $second, $type);
	}

	public function crossJoin($table, $first=null, $operator=null, $second=null): self {
		assert(is_string($table));
		return parent::crossJoin($this->nadyDB->formatSql($table), $first, $operator, $second);
	}

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
	 * @param array<string,mixed>|array<array<string,mixed>> $values
	 */
	public function chunkInsert(array $values): bool {
		if (!isset($values[0])) {
			return $this->insert($values);
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
	 * @param array<string,mixed>|array<array<string,mixed>> $values
	 * @param string|list<string>                            $uniqueBy
	 * @param ?list<string>                                  $update
	 */
	public function chunkUpsert(array $values, array|string $uniqueBy, ?array $update=null): int {
		if (!isset($values[0])) {
			return $this->upsert($values, $uniqueBy, $update);
		}
		$chunkSize = (int)floor($this->nadyDB->maxPlaceholders / count($values[0]));
		$result = 0;
		while (count($values)) {
			$chunk = array_splice($values, 0, $chunkSize);
			$result += $this->upsert($chunk, $uniqueBy, $update);
		}
		return $result;
	}

	/** @phpstan-param ReflectionClass<object> $refClass */
	protected function guessVarTypeFromReflection(ReflectionClass $refClass, string $colName): ?string {
		$refProp = $refClass->getProperty($colName);
		$refType = $refProp->getType();
		if ($refType instanceof ReflectionNamedType) {
			return $refType->getName();
		}
		return null;
	}

	/**
	 * @param class-string<T> $className
	 * @param list<?string>   $values
	 *
	 * @template T of object
	 *
	 * @return T
	 */
	protected function convertToClass(PDOStatement $ps, string $className, array $values): object {
		$cacheLines = [];
		$row = [];
		$colMappings = [];
		$refClass = new ReflectionClass($className);
		foreach ($refClass->getProperties() as $refProperty) {
			$colMapping = $refProperty->getAttributes(ColName::class);
			if (count($colMapping)) {
				$colMappings[$colMapping[0]->newInstance()->col] = $refProperty->getName();
			}
		}
		$numColumns = count($values);
		for ($col=0; $col < $numColumns; $col++) {
			$colMeta = $ps->getColumnMeta($col);
			if ($colMeta === false) {
				$this->logger->error(
					'Error trying to get the meta information for {className}, column {colNum}: {error}',
					[
						'className' => $className,
						'colNum' => $col,
						'error' => "query didn't return that many columns",
					]
				);
				continue;
			}
			$colName = $colMeta['name'];
			$propName = $colMappings[$colMeta['name']] ?? $colMeta['name'];
			if ($values[$col] === null) {
				try {
					$refProp = $refClass->getProperty($propName);
					$refType = $refProp->getType();
					if (isset($refType) && $refType->allowsNull()) {
						$row[$colName] = null;
					}
				} catch (ReflectionException $e) {
					$row[$colName] = null;
				} catch (Throwable $e) {
					$this->logger->error(
						'Error trying to get the meta information for {className}, column {colNum}: {error}',
						[
							'className' => $className,
							'colNum' => $col,
							'error' => $e->getMessage(),
							'exception' => $e,
							'colMeta' => $colMeta,
						]
					);
				}
				continue;
			}
			assert(isset($values[$col]));
			try {
				if (!$refClass->hasProperty($propName)) {
					$this->logger->error("Unable to load data into {class}::\${property}: property doesn't exist", [
						'class' => $refClass->getName(),
						'property' => $propName,
						'exception' => new Exception(),
					]);
					continue;
				}
				$type = $this->guessVarTypeFromReflection($refClass, $propName);
				$refProp = $refClass->getProperty($propName);
				$readMap = $refProp->getAttributes(NCA\DB\MapRead::class);
				if (count($readMap)) {
					foreach ($readMap as $mapper) {
						$mapper = $mapper->newInstance();
						$row[$colName] = $mapper->map($values[$col]);
						$cacheLines []= "{$colName}: unserialize(" . var_export(serialize($mapper), true) . ')->map($data->' . $colName . '),';
					}
				} else {
					if ($type === 'bool') {
						$row[$colName] = (bool)$values[$col];
						$cacheLines []= "{$colName}: isset(\$data->{$colName}) ? (bool)\$data->{$colName} : null,";
					} elseif ($type === 'int') {
						$row[$colName] = (int)$values[$col];
						$cacheLines []= "{$colName}: isset(\$data->{$colName}) ? (int)\$data->{$colName} : null,";
					} elseif ($type === 'float') {
						$row[$colName] = (float)$values[$col];
						$cacheLines []= "{$colName}: isset(\$data->{$colName}) ? (float)\$data->{$colName} : null,";
					} elseif ($type === \DateTime::class || $type === DateTime::class) {
						$row[$colName] = (new DateTime())->setTimestamp((int)$values[$col]);
						$cacheLines []= "{$colName}: isset(\$data->{$colName}) ? (new \\Safe\\DateTime())->setTimestamp((int)\$data->{$colName}) : null,";
					} elseif ($type === \DateTimeImmutable::class || $type === DateTimeImmutable::class) {
						$row[$colName] = (new DateTimeImmutable())->setTimestamp((int)$values[$col]);
						$cacheLines []= "{$colName}: isset(\$data->{$colName}) ? (new \\Safe\\DateTimeImmutable())->setTimestamp((int)\$data->{$colName}) : null,";
					} elseif ($type === \DateTimeInterface::class) {
						$row[$colName] = (new DateTimeImmutable())->setTimestamp((int)$values[$col]);
						$cacheLines []= "{$colName}: isset(\$data->{$colName}) ? (new \\Safe\\DateTimeImmutable())->setTimestamp((int)\$data->{$colName}) : null,";
					} elseif ($type === UuidInterface::class) {
						$row[$colName] = Uuid::fromString($values[$col]);
						$cacheLines []= "{$colName}: isset(\$data->{$colName}) ? \\" . Uuid::class . "::fromString(\$data->{$colName}) : null,";
					} elseif (is_a($type, \BackedEnum::class, true)) {
						$row[$colName] = $type::from($values[$col]);
						$cacheLines []= "{$colName}: isset(\$data->{$colName}) ? \\{$type}::from(\$data->{$colName}) : null,";
					} else {
						$row[$colName] = $values[$col];
						$cacheLines []= "{$colName}: \$data->{$colName},";
					}
				}
				if ($propName !== $colName) {
					$row[$propName] = $row[$colName];
					unset($row[$colName]);
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
		try {
			$constructor = $refClass->getMethod('__construct');
			if (count($constructor->getParameters())) {
				$obj = $refClass->newInstance(...$row);
				return $obj;
			}
		} catch (ReflectionException) {
		} catch (\Throwable $e) {
			$this->logger->error('Cannot create instance of {class}: {error}. Given: {data}, constructed from {values}', [
				'class' => $refClass->name,
				'error' => $e->getMessage(),
				'data' => $row,
				'values' => $values,
				'exception' => $e,
			]);
			throw $e;
		}
		$obj = new $className();
		foreach ($row as $key => $value) {
			$obj->{$key} = $value;
		}
		return $obj;
	}

	protected function dbFunc(string $function): string {
		$type = $this->nadyDB->getType();
		switch (strtolower($function)) {
			case 'length':
				if ($type === DB\Type::MySQL) {
					return 'length';
				}
				break;
			default:
				return $function;
		}
		return $function;
	}

	/** @param class-string $className */
	private function compileFromClass(string $className, stdClass $data): void {
		$cacheLines = [];
		$colMappings = [];
		$refClass = new ReflectionClass($className);
		foreach ($refClass->getProperties() as $refProperty) {
			$colMapping = $refProperty->getAttributes(ColName::class);
			if (count($colMapping)) {
				$colMappings[$colMapping[0]->newInstance()->col] = $refProperty->getName();
			}
		}
		foreach (get_object_vars($data) as $colName => $colValue) {
			$propName = $colMappings[$colName] ?? $colName;
			try {
				if (!$refClass->hasProperty($propName)) {
					$this->logger->error("Unable to load data into {class}::\${property}: property doesn't exist", [
						'class' => $refClass->getName(),
						'property' => $propName,
						'exception' => new Exception(),
					]);
					continue;
				}
				$type = $this->guessVarTypeFromReflection($refClass, $propName);
				$refProp = $refClass->getProperty($propName);
				$defaultValue = 'null';
				if ($refProp->hasDefaultValue()) {
					$defaultValue = var_export($refProp->getDefaultValue(), true);
				}
				$readMap = $refProp->getAttributes(NCA\DB\MapRead::class);
				if (count($readMap)) {
					foreach ($readMap as $mapper) {
						$cacheLines []= "{$propName}: unserialize(" . var_export(serialize($mapper->newInstance()), true) . ')->map($data->' . $colName . '),';
					}
				} else {
					if ($type === 'bool') {
						$cacheLines []= "{$propName}: isset(\$data->{$colName}) ? (bool)\$data->{$colName} : {$defaultValue},";
					} elseif ($type === 'int') {
						$cacheLines []= "{$propName}: isset(\$data->{$colName}) ? (int)\$data->{$colName} : {$defaultValue},";
					} elseif ($type === 'float') {
						$cacheLines []= "{$propName}: isset(\$data->{$colName}) ? (float)\$data->{$colName} : {$defaultValue},";
					} elseif ($type === \DateTime::class || $type === DateTime::class) {
						$cacheLines []= "{$propName}: isset(\$data->{$colName}) ? (new \\Safe\\DateTime())->setTimestamp((int)\$data->{$colName}) : {$defaultValue},";
					} elseif ($type === \DateTimeImmutable::class || $type === DateTimeImmutable::class) {
						$cacheLines []= "{$propName}: isset(\$data->{$colName}) ? (new \\Safe\\DateTimeImmutable())->setTimestamp((int)\$data->{$colName}) : {$defaultValue},";
					} elseif ($type === \DateTimeInterface::class) {
						$cacheLines []= "{$propName}: isset(\$data->{$colName}) ? (new \\Safe\\DateTimeImmutable())->setTimestamp((int)\$data->{$colName}) : {$defaultValue},";
					} elseif ($type === UuidInterface::class) {
						$cacheLines []= "{$propName}: isset(\$data->{$colName}) ? \\" . Uuid::class . "::fromString(\$data->{$colName}) : {$defaultValue},";
					} elseif (is_a($type, \BackedEnum::class, true)) {
						$cacheLines []= "{$propName}: isset(\$data->{$colName}) ? \\{$type}::from(\$data->{$colName}) : {$defaultValue},";
					} else {
						$cacheLines []= "{$propName}: isset(\$data->{$colName}) ? \$data->{$colName} : {$defaultValue},";
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

	private function getCacheFile(string $className): string {
		$safeClassName = Safe::pregReplace(
			'/[^a-zA-Z0-9]/',
			'_',
			$className,
		);
		return $this->config->paths->cache . "/db/cmd_{$safeClassName}_compiler.php";
	}

	/**
	 * Compile and save the cache
	 *
	 * @param string[] $cacheLines
	 */
	private function compileCache(string $className, array $cacheLines): void {
		$nsParts = explode('\\', $className);
		$classShortName = array_pop($nsParts);
		$nameSpace = implode('\\', $nsParts);
		$code = '<?php' . \PHP_EOL.
			\PHP_EOL.
			"namespace {$nameSpace};" . \PHP_EOL.
			\PHP_EOL.
			"class {$classShortName}_compiler {" . \PHP_EOL.
			"\tpublic static function fromDB(\\stdClass \$data): {$classShortName} {" . \PHP_EOL.
			"\t\treturn new {$classShortName}(" . \PHP_EOL.
			"\t\t\t". implode(\PHP_EOL . "\t\t\t", $cacheLines) . \PHP_EOL.
			"\t\t);" . \PHP_EOL.
			"\t}" . \PHP_EOL.
			'}' . \PHP_EOL;
		$this->fs->write(
			$this->getCacheFile($className),
			$code
		);
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
		$cacheClass = "{$className}_compiler";
		$cacheFile = $this->getCacheFile($className);
		$data = $this->get();
		if ($data->isEmpty()) {
			return $data;
		}
		if (!class_exists($cacheClass)) {
			if (!$this->fs->exists($cacheFile)) {
				$this->compileFromClass($className, $data->firstOrFail());
			}
			require_once $cacheFile;
		}
		if (class_exists($cacheClass)) {
			return $data->map($cacheClass::fromDB(...));
		}
		throw new \Exception('Unable to infer a type from the given SQL result');
	}
}
