<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Attributes\DB\ColName;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Exceptions\SQLException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\{Uuid, UuidInterface};
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Safe\{DateTime, DateTimeImmutable};
use Throwable;

class QueryBuilder extends Builder {
	private const CLASS_SEP = '⚡️';

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
	 * @return Collection<int,int>
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

	protected function guessVarTypeFromReflection(ReflectionParameter $refParam): ?string {
		$refType = $refParam->getType();
		if ($refType instanceof ReflectionNamedType) {
			return $refType->getName();
		}
		return null;
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
	private function compileForClass(string $className): void {
		$cacheLines = [];
		$colMappings = [];
		$refClass = new ReflectionClass($className);
		foreach ($refClass->getProperties() as $refProperty) {
			$colMapping = $refProperty->getAttributes(ColName::class);
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
						$cacheLines []= "{$paramName}: isset(\$data->{$colName}) ? {$cacheLine} : {$defaultValue},";
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
	 * Compile and save the cache
	 *
	 * @param string[] $cacheLines
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
			require_once $fileName;
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

		$data = $this->get();
		if ($data->isEmpty()) {
			return $data;
		}

		/** @var Collection<int,\stdClass> $data */
		if (!class_exists($cacheClass, false)) {
			$this->compileForClass($className);
		}
		if (class_exists($cacheClass, false)) {
			return $data->map($cacheClass::fromDB(...));
		}
		throw new \Exception("Unable to infer a database mapper for {$className}");
	}
}
