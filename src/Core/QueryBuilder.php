<?php declare(strict_types=1);

namespace Nadybot\Core;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Database\Query\Expression;

class QueryBuilder extends Builder {
	public DB $nadyDB;

	public function asObj(string $class=null): Collection {
		if ($class === null) {
			return new Collection($this->nadyDB->query($this->toSql(), ...$this->getBindings()));
		} else {
			return new Collection($this->nadyDB->fetchAll($class, $this->toSql(), ...$this->getBindings()));
		}
	}

	public function as(string $as): string {
		return " as " . $this->grammar->wrap($as);
	}

	public function orderByFunc(string $function, $param, string $direction="asc"): self {
		$function = $this->dbFunc($function);
		return $this->orderByRaw(
			"$function({$param}) {$direction}"
		);
	}

	public function orderByColFunc(string $function, $column, string $direction="asc"): self {
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

	public function colFunc(string $function, $column, string $as=null): Expression {
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

	public function rawFunc(string $function, $param, string $as=null): Expression {
		$function = $this->dbFunc($function);
		return $this->raw(
			"$function($param)".
			(isset($as) ? " AS " . $this->grammar->wrap($as) : "")
		);
	}

	public function orWhereIlike(string $column, string $value): self {
		return $this->orWhere($this->colFunc("LOWER", $column), "like", strtolower($value));
	}

	public function whereIlike(string $column, string $value, string $boolean='and'): self {
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

	public function newQuery() {
		$instance = new static($this->connection, $this->grammar, $this->processor);
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
