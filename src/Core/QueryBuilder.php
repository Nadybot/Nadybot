<?php declare(strict_types=1);

namespace Nadybot\Core;

use DateTime;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\{Builder, Expression};
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes as NCA;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Throwable;

class QueryBuilder extends Builder {
	public DB $nadyDB;

	public LoggerWrapper $logger;

	/**
	 * @template T
	 *
	 * @param class-string<T> $class
	 *
	 * @return Collection<T>
	 */
	public function asObj(string $class): Collection {
		return new Collection($this->fetchAll($class, $this->toSql(), ...$this->getBindings()));
	}

	/**
	 * Pluck values as type $type
	 *
	 * @deprecated 6.1.0
	 */
	public function pluckAs(string $column, string $type): Collection {
		return $this->pluck($column)
			->map(function (mixed $value, int $key) use ($type): mixed {
				\Safe\settype($value, $type);
				return $value;
			});
	}

	/**
	 * Pluck values as strings
	 *
	 * @return Collection<string>
	 */
	public function pluckStrings(string $column): Collection {
		return $this->pluck($column)
			->map(function (mixed $value, int $key): string {
				return (string)$value;
			});
	}

	/**
	 * Pluck values as ints
	 *
	 * @return Collection<int>
	 */
	public function pluckInts(string $column): Collection {
		return $this->pluck($column)
			->map(function (mixed $value, int $key): int {
				return (int)$value;
			});
	}

	public function as(string $as): string {
		return " as " . $this->grammar->wrap($as);
	}

	public function orderByFunc(string $function, mixed $param, string $direction="asc"): self {
		$function = $this->dbFunc($function);
		return $this->orderByRaw(
			"{$function}({$param}) {$direction}"
		);
	}

	public function orderByColFunc(string $function, mixed $column, string $direction="asc"): self {
		$function = $this->dbFunc($function);
		if (!is_array($column)) {
			$column = [$column];
		}
		$column = array_map([$this->grammar, "wrap"], $column);
		$cols = join(", ", $column);
		return $this->orderByRaw(
			"{$function}({$cols}) {$direction}"
		);
	}

	public function colFunc(string $function, mixed $column, ?string $as=null): Expression {
		$function = $this->dbFunc($function);
		if (!is_array($column)) {
			$column = [$column];
		}
		$column = array_map([$this->grammar, "wrap"], $column);
		$cols = join(", ", $column);
		return $this->raw(
			"{$function}({$cols})".
			(isset($as) ? " AS " . $this->grammar->wrap($as) : "")
		);
	}

	public function rawFunc(string $function, mixed $param, ?string $as=null): Expression {
		$function = $this->dbFunc($function);
		return $this->raw(
			"{$function}({$param})".
			(isset($as) ? " AS " . $this->grammar->wrap($as) : "")
		);
	}

	public function orWhereIlike(string $column, string $value): self {
		/**
		 * @psalm-suppress ImplicitToStringCast
		 * @phpstan-ignore-next-line
		 */
		return $this->orWhere($this->colFunc("LOWER", $column), "like", strtolower($value));
	}

	public function whereIlike(string $column, string $value, string $boolean='and'): self {
		/**
		 * @psalm-suppress ImplicitToStringCast
		 * @phpstan-ignore-next-line
		 */
		return $this->where($this->colFunc("LOWER", $column), "like", strtolower($value), $boolean);
	}

	public function join($table, $first, $operator=null, $second=null, $type='inner', $where=false): self {
		if (is_string($table)) {
			$table = $this->nadyDB->formatSql($table);
		}
		return parent::join($table, $first, $operator, $second, $type);
	}

	public function crossJoin($table, $first=null, $operator=null, $second=null): self {
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

	/** @phpstan-param ReflectionClass<object> $refClass */
	protected function guessVarTypeFromReflection(ReflectionClass $refClass, string $colName): ?string {
		$refProp = $refClass->getProperty($colName);
		$refType = $refProp->getType();
		if ($refType instanceof ReflectionNamedType) {
			return $refType->getName();
		}
		return null;
	}

	/** @param array<int,null|string> $values */
	protected function convertToClass(PDOStatement $ps, string $className, array $values): object {
		$row = new $className();
		$refClass = new ReflectionClass($row);
		$numColumns = count($values);
		for ($col=0; $col < $numColumns; $col++) {
			$colMeta = $ps->getColumnMeta($col);
			if ($colMeta === false) {
				$this->logger->error(
					"Error trying to get the meta information for {className}, column {colNum}: {error}",
					[
						"className" => $className,
						"colNum" => $col,
						"error" => "query didn't return that many columns",
					]
				);
				continue;
			}
			$colName = $colMeta['name'];
			if ($values[$col] === null) {
				try {
					$refProp = $refClass->getProperty($colName);
					$refType = $refProp->getType();
					if (isset($refType) && $refType->allowsNull()) {
						$row->{$colName} = null;
					}
				} catch (ReflectionException $e) {
					$row->{$colName} = null;
				} catch (Throwable $e) {
					$this->logger->error(
						"Error trying to get the meta information for {className}, column {colNum}: {error}",
						[
							"className" => $className,
							"colNum" => $col,
							"error" => $e->getMessage(),
							"exception" => $e,
							"colMeta" => $colMeta,
						]
					);
				}
				continue;
			}
			try {
				if (!$refClass->hasProperty($colName)) {
					$this->logger->error("Unable to load data into " . $refClass->getName() . "::\${$colName}: property doesn't exist", [
						"exception" => new Exception(),
					]);
					continue;
				}
				$type = $this->guessVarTypeFromReflection($refClass, $colName);
				$refProp = $refClass->getProperty($colName);
				$readMap = $refProp->getAttributes(NCA\DB\MapRead::class);
				if (count($readMap)) {
					foreach ($readMap as $mapper) {
						/** @var NCA\DB\MapRead */
						$mapper = $mapper->newInstance();
						$row->{$colName} = $mapper->map($values[$col]);
					}
				} else {
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
				}
			} catch (Throwable $e) {
				$this->logger->error($e->getMessage(), ["exception" => $e]);
				throw $e;
			}
		}
		return $row;
	}

	protected function dbFunc(string $function): string {
		$type = $this->nadyDB->getType();
		switch (strtolower($function)) {
			case "length":
				if ($type === DB::MSSQL) {
					return "len";
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
		$this->logger->debug($sql, [
			"params" => $params,
			"driver" => $conn->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME),
			"version" => $conn->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
		]);

		try {
			$ps = $conn->getPdo()->prepare($sql);
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
			if ($this->nadyDB->getType() === DB::SQLITE && $e->errorInfo[1] === 17) {
				// fix for Sqlite schema changed error (retry the query)
				return $this->executeQuery($sql, $params);
			}
			if ($this->nadyDB->getType() === DB::MYSQL && in_array($e->errorInfo[1], [1927, 2006], true)) {
				$this->logger->warning(
					'DB had recoverable error: ' . trim($e->errorInfo[2]) . ' - reconnecting'
				);
				$conn->reconnect();
				return $this->executeQuery(...func_get_args());
			}
			throw new SQLException("Error: {$e->errorInfo[2]}\nQuery: {$sql}\nParams: " . \Safe\json_encode($params, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), 0, $e);
		}
	}

	/**
	 * Execute an SQL statement and return all rows as an array of objects of the given class
	 *
	 * @return object[]
	 */
	private function fetchAll(string $className, string $sql, mixed ...$args): array {
		$sql = $this->nadyDB->formatSql($sql);

		$sql = $this->nadyDB->applySQLCompatFixes($sql);
		$ps = $this->executeQuery($sql, $args);
		$data = $ps->fetchAll(
			PDO::FETCH_FUNC,
			function (mixed ...$values) use ($ps, $className): object {
				/** @var mixed[] $values */
				return $this->convertToClass($ps, $className, $values);
			}
		);
		return $data;
	}
}
