<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Closure;
use InvalidArgumentException;
use Nadybot\Core\Attributes\JSON;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;

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
		if (count($params) < 2) {
			throw new InvalidArgumentException(
				"The news tile {$name}'s callback {$funcHint} does not accept ".
				"at least 2 arguments"
			);
		}
		if ($params[0]->hasType()) {
			$type = $params[0]->getType();
			if ($type instanceof ReflectionNamedType) {
				$typeNames =[$type->getName()];
			} elseif ($type instanceof ReflectionUnionType) {
				$typeNames = array_map(fn (ReflectionNamedType $type) => $type->getName(), $type->getTypes());
			}
			if (!in_array("string", $typeNames??[])) {
				throw new InvalidArgumentException(
					"The news tile {$name}'s callback {$funcHint} does not accept ".
					"a string as first argument"
				);
			}
		}
		if ($params[1]->hasType()) {
			$type = $params[1]->getType();
			if ($type instanceof ReflectionNamedType) {
				$typeNames =[$type->getName()];
			} elseif ($type instanceof ReflectionUnionType) {
				$typeNames = array_map(fn (ReflectionNamedType $type) => $type->getName(), $type->getTypes());
			}
			if (!in_array("callable", $typeNames??[])) {
				throw new InvalidArgumentException(
					"The news tile {$name}'s callback {$funcHint} does not accept ".
					"a callable as second argument"
				);
			}
		}
	}

	public function call(string $sender, callable $callback): void {
		$func = $this->callback;
		$func($sender, $callback);
	}
}
