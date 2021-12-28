<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use DateTime;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Database\Query\Expression;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

class QueryBuilder extends Builder {
	public DB $nadyDB;

	public LoggerWrapper $logger;

	private static array $meta = [];
	private static array $metaTypes = [];

	/**
	 * Populate and return an array of type changers for a query
	 *
	 * @return Closure[]
	 */
	protected function getTypeChanger(PDOStatement $ps, object $row): array {
		$metaKey = md5($ps->queryString);
		$numColumns = $ps->columnCount();
		if (isset(static::$meta[$metaKey])) {
			return static::$meta[$metaKey];
		}
		static::$meta[$metaKey] = [];
		for ($col=0; $col < $numColumns; $col++) {
			$colMeta = $ps->getColumnMeta($col);
			$type = $this->guessVarTypeFromColMeta($colMeta, $colMeta["name"]);
			$refProp = new ReflectionProperty($row, $colMeta["name"]);
			$refProp->setAccessible(true);
			if ($type === "bool") {
				static::$meta[$metaKey] []= function(object $row) use ($refProp): void {
					$stringValue = $refProp->getValue($row);
					if ($stringValue !== null) {
						$refProp->setValue($row, (bool)$stringValue);
					}
				};
			} elseif ($type === "int") {
				static::$meta[$metaKey] []= function(object $row) use ($refProp): void {
					$stringValue = $refProp->getValue($row);
					if ($stringValue !== null) {
						$refProp->setValue($row, (int)$stringValue);
					}
				};
			}
		}
		return static::$meta[$metaKey];
	}

	/**
	 * Execute an SQL statement and return all rows as an array of objects
	 *
	 * @return \Nadybot\Core\DBRow[] All returned rows
	 */
	protected function query(string $sql, ...$args): array {
		$sql = $this->nadyDB->formatSql($sql);

		$sql = $this->nadyDB->applySQLCompatFixes($sql);
		$ps = $this->executeQuery($sql, $args);
		$ps->setFetchMode(PDO::FETCH_CLASS, DBRow::class);
		$result = [];
		while ($row = $ps->fetch(PDO::FETCH_CLASS)) {
			/** @var DBRow $row */
			$typeChangers = $this->getTypeChanger($ps, $row);
			foreach ($typeChangers as $changer) {
				$changer($row);
			}
			$result []= $row;
		}
		return $result;
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

	public static function clearMetaCache(): void {
		static::$meta = [];
		static::$metaTypes = [];
	}

	protected function convertToClass(PDOStatement $ps, string $className, array $values): ?object {
		$row = new $className();
		$refClass = new ReflectionClass($row);
		$metaKey = md5($ps->queryString);
		$numColumns = $ps->columnCount();
		if (!isset(static::$metaTypes[$metaKey])) {
			static::$metaTypes[$metaKey] = [];
			for ($col=0; $col < $numColumns; $col++) {
				static::$metaTypes[$metaKey] []= $ps->getColumnMeta($col);
			}
		}
		$meta = static::$metaTypes[$metaKey];
		for ($col=0; $col < $numColumns; $col++) {
			$colMeta = $meta[$col];
			$colName = $colMeta['name'];
			if ($values[$col] === null) {
				try {
					$refProp = $refClass->getProperty($colName);
					$refType = $refProp->getType();
					if (isset($refType) && $refType->allowsNull()) {
						$row->{$colName} = $values[$col];
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
				$this->logger->error($e->getMessage(), ["exception" => $e]);
				throw $e;
			}
		}
		return $row;
	}


	/**
	 * Execute an SQL query, returning the statement object
	 *
	 * @throws SQLException when the query errors
	 */
	private function executeQuery(string $sql, array $params): PDOStatement {
		/** @var Connection */
		$conn = $this->getConnection();
		$this->logger->debug($sql, [
			"params" => $params,
			"driver" => $conn->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME),
			"version" => $conn->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION)
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
			throw new SQLException("Error: {$e->errorInfo[2]}\nQuery: $sql\nParams: " . json_encode($params, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), 0, $e);
		}
	}

	/**
	 * Execute an SQL statement and return all rows as an array of objects of the given class
	 */
	private function fetchAll(string $className, string $sql, ...$args): array {
		$sql = $this->nadyDB->formatSql($sql);

		$sql = $this->nadyDB->applySQLCompatFixes($sql);
		$ps = $this->executeQuery($sql, $args);
		return $ps->fetchAll(
			PDO::FETCH_FUNC,
			function (mixed ...$values) use ($ps, $className): ?object {
				return $this->convertToClass($ps, $className, $values);
			}
		);
	}

	public function asObj(string $class=null): Collection {
		if ($class === null) {
			return new Collection($this->query($this->toSql(), ...$this->getBindings()));
		} else {
			return new Collection($this->fetchAll($class, $this->toSql(), ...$this->getBindings()));
		}
	}

	/**
	 * Pluck values as type $type
	 *
	 * @param string $column
	 * @param string $type
	 * @return \Illuminate\Support\Collection
	 */
	public function pluckAs(string $column, string $type): Collection {
		return $this->pluck($column)
			->map(function (mixed $value, int $key) use ($type): mixed {
				settype($value, $type);
				return $value;
			});
	}

	public function as(string $as): string {
		return " as " . $this->grammar->wrap($as);
	}

	public function orderByFunc(string $function, mixed $param, string $direction="asc"): self {
		$function = $this->dbFunc($function);
		return $this->orderByRaw(
			"$function({$param}) {$direction}"
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
			"$function({$cols}) {$direction}"
		);
	}

	public function colFunc(string $function, mixed $column, string $as=null): Expression {
		$function = $this->dbFunc($function);
		if (!is_array($column)) {
			$column = [$column];
		}
		$column = array_map([$this->grammar, "wrap"], $column);
		$cols = join(", ", $column);
		return $this->raw(
			"$function({$cols})".
			(isset($as) ? " AS " . $this->grammar->wrap($as) : "")
		);
	}

	public function rawFunc(string $function, mixed $param, string $as=null): Expression {
		$function = $this->dbFunc($function);
		return $this->raw(
			"$function($param)".
			(isset($as) ? " AS " . $this->grammar->wrap($as) : "")
		);
	}

	public function orWhereIlike(string $column, string $value): self {
		/** @psalm-suppress ImplicitToStringCast */
		return $this->orWhere($this->colFunc("LOWER", $column), "like", strtolower($value));
	}

	public function whereIlike(string $column, string $value, string $boolean='and'): self {
		/** @psalm-suppress ImplicitToStringCast */
		return $this->where($this->colFunc("LOWER", $column), "like", strtolower($value), $boolean);
	}

	public function join($table, $first, $operator=null, $second=null, $type='inner', $where=false): self {
		return parent::join($this->nadyDB->formatSql($table), $first, $operator, $second, $type);
	}

	public function crossJoin($table, $first=null, $operator=null, $second=null): self {
		return parent::crossJoin($this->nadyDB->formatSql($table), $first, $operator, $second);
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
}
