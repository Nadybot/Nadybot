<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Closure;
use InvalidArgumentException;
use Nadybot\Core\Attributes\JSON;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;

class NewsTile {
	/** The name of this news tile */
	public string $name;

	/** A description what this news tile shows */
	public string $description;

	/** An example what this could look like if there was data */
	public ?string $example;

	/** The callback that returns the news tile data */
	#[JSON\Ignore]
	public Closure $callback;

	public function __construct(string $name, callable $callback) {
		if (strpos($name, ";") !== false) {
			throw new InvalidArgumentException("The news tile {$name} contains a semicolon.");
		}
		$this->name = $name;
		$this->callback = Closure::fromCallable($callback);
		$ref = new ReflectionFunction($this->callback);
		$funcHint = "function";
		$scopeClass = $ref->getClosureScopeClass();
		if ($scopeClass !== null) {
			$funcHint .= " {$scopeClass->name}::{$ref->name}()";
		}
		$params = $ref->getParameters();
		if (count($params) !== 1) {
			throw new InvalidArgumentException(
				"The news tile {$name}'s callback {$funcHint} does not accept ".
				"exactly 1 argument"
			);
		}
		if ($params[0]->hasType()) {
			$type = $params[0]->getType();
			if ($type instanceof ReflectionNamedType) {
				$typeNames = [$type->getName()];
			} elseif ($type instanceof \ReflectionIntersectionType) {
				throw new InvalidArgumentException(
					"The news tile {$name}'s callback {$funcHint} has an unsupported type ".
					"as first argument"
				);
			} elseif ($type instanceof ReflectionUnionType) {
				$typeNames = [];
				foreach ($type->getTypes() as $type) {
					if ($type instanceof ReflectionNamedType) {
						$typeNames []= $type->getName();
					}
				}
			} else {
				throw new InvalidArgumentException(
					"The news tile {$name}'s callback {$funcHint} has an unsupported type ".
					"as first argument"
				);
			}
			if (!in_array("string", $typeNames)) {
				throw new InvalidArgumentException(
					"The news tile {$name}'s callback {$funcHint} does not accept ".
					"a string as first argument"
				);
			}
		}
	}

	public function call(string $sender): ?string {
		$func = $this->callback;
		$result = $func($sender);
		if (isset($result) && !is_string($result)) {
			throw new RuntimeException("The news tile {$this->name} didn't return proper data");
		}
		return $result;
	}
}
