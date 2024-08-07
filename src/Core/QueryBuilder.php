<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\json_encode;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Attributes\DB\ColName;
use Nadybot\Core\Exceptions\SQLException;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Safe\{DateTime, DateTimeImmutable};
use Throwable;

class QueryBuilder extends Builder {
	#[NCA\Inject]
	public DB $nadyDB;

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
			/** @var list<T> */
			$result = $this->fetchAll($class, $this->toSql(), ...$this->getBindings());
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

		/** @var Collection<int,T> $x */
		$x = collect($result);
		return $x;
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $class
	 *
	 * @return list<T>
	 */
	public function asObjArr(string $class): array {
		/** @var list<T> */
		$result = $this->fetchAll($class, $this->toSql(), ...$this->getBindings());
		return $result;
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
					}
				} else {
					if ($type === 'bool') {
						$row[$colName] = (bool)$values[$col];
					} elseif ($type === 'int') {
						$row[$colName] = (int)$values[$col];
					} elseif ($type === 'float') {
						$row[$colName] = (float)$values[$col];
					} elseif ($type === \DateTime::class || $type === DateTime::class) {
						$row[$colName] = (new DateTime())->setTimestamp((int)$values[$col]);
					} elseif ($type === \DateTimeImmutable::class || $type === DateTimeImmutable::class) {
						$row[$colName] = (new DateTimeImmutable())->setTimestamp((int)$values[$col]);
					} elseif ($type === \DateTimeInterface::class) {
						$row[$colName] = (new DateTimeImmutable())->setTimestamp((int)$values[$col]);
					} elseif ($type === UuidInterface::class) {
						$row[$colName] = Uuid::fromString($values[$col]);
					} elseif (is_a($type, \BackedEnum::class, true)) {
						$row[$colName] = $type::from($values[$col]);
					} else {
						$row[$colName] = $values[$col];
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

	/**
	 * Execute an SQL query, returning the statement object
	 *
	 * @param array<mixed> $params
	 *
	 * @throws SQLException when the query errors
	 */
	private function executeQuery(string $sql, array $params): PDOStatement {
		/** @var Connection */
		$conn = $this->getConnection();
		$this->logger->debug('{sql}', [
			'sql' => $sql,
			'params' => $params,
			'driver' => $conn->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME),
			'version' => $conn->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
		]);

		try {
			$ps = $conn->getPdo()->prepare($sql);
			$count = 1;
			foreach ($params as $param) {
				if ($param === 'NULL' || $param === null) {
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
			$e->errorInfo ??= [0, ''];
			if ($this->nadyDB->getType() === DB\Type::SQLite && $e->errorInfo[1] === 17) {
				// fix for Sqlite schema changed error (retry the query)
				return $this->executeQuery($sql, $params);
			}
			if ($this->nadyDB->getType() === DB\Type::MySQL && in_array($e->errorInfo[1], [1_927, 2_006], true)) {
				$this->logger->warning('DB had recoverable error: {error} - reconnecting', [
					'error' => trim($e->errorInfo[2]),
					'exception' => $e,
				]);
				$conn->reconnect();
				return $this->executeQuery(...func_get_args());
			}
			throw new SQLException("{$e->errorInfo[2]}\nQuery: {$sql}\nParams: " . json_encode($params, \JSON_PRETTY_PRINT|\JSON_UNESCAPED_SLASHES), 0, $e);
		}
	}

	/**
	 * Execute an SQL statement and return all rows as an array of objects of the given class
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $className
	 *
	 * @return list<T>
	 */
	private function fetchAll(string $className, string $sql, mixed ...$args): array {
		$sql = $this->nadyDB->formatSql($sql);

		$sql = $this->nadyDB->applySQLCompatFixes($sql);
		$ps = $this->executeQuery($sql, $args);

		/** @var list<T> */
		$data = $ps->fetchAll(
			PDO::FETCH_FUNC,
			function (mixed ...$values) use ($ps, $className): object {
				assert(array_is_list($values));

				/** @var list<mixed> $values */
				return $this->convertToClass($ps, $className, $values);
			}
		);
		return $data;
	}
}
