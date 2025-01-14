<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Closure;
use InvalidArgumentException;
use Nadybot\Core\Attributes\JSON;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;

/** A news tile with name and description */
class NewsTile {
	/**
	 * @param string  $name        The name of this news tile
	 * @param Closure $callback    A description what this news tile shows
	 * @param string  $description An example what this could look like if there was data
	 * @param ?string $example     The callback that returns the news tile data
	 */
	public function __construct(
		public string $name,
		#[JSON\Ignore] public Closure $callback,
		public string $description,
		public ?string $example,
	) {
		if (str_contains($name, ';')) {
			throw new InvalidArgumentException("The news tile {$name} contains a semicolon.");
		}
		$ref = new ReflectionFunction($this->callback);
		$funcHint = 'function';
		$scopeClass = $ref->getClosureScopeClass();
		if ($scopeClass !== null) {
			$funcHint .= " {$scopeClass->name}::{$ref->name}()";
		}
		$params = $ref->getParameters();
		if (count($params) !== 1) {
			throw new InvalidArgumentException(
				"The news tile {$name}'s callback {$funcHint} does not accept ".
				'exactly 1 argument'
			);
		}
		if ($params[0]->hasType()) {
			$type = $params[0]->getType();
			if ($type instanceof ReflectionNamedType) {
				$typeNames = [$type->getName()];
			} elseif ($type instanceof \ReflectionIntersectionType) {
				throw new InvalidArgumentException(
					"The news tile {$name}'s callback {$funcHint} has an unsupported type ".
					'as first argument'
				);
			} elseif ($type instanceof ReflectionUnionType) {
				$typeNames = [];
				foreach ($type->getTypes() as $partType) {
					if ($partType instanceof ReflectionNamedType) {
						$typeNames []= $partType->getName();
					}
				}
			} else {
				throw new InvalidArgumentException(
					"The news tile {$name}'s callback {$funcHint} has an unsupported type ".
					'as first argument'
				);
			}
			if (!in_array('string', $typeNames, true)) {
				throw new InvalidArgumentException(
					"The news tile {$name}'s callback {$funcHint} does not accept ".
					'a string as first argument'
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
