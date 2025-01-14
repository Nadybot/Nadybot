<?php declare(strict_types=1);

namespace Nadybot\Core;

use InvalidArgumentException;

/** Class specs (name, PHP-class, description, and parameters) */
class ClassSpec {
	/**
	 * @param class-string            $name
	 * @param class-string            $class
	 * @param list<FunctionParameter> $params
	 */
	public function __construct(
		public string $name,
		public string $class,
		public ?string $description=null,
		public array $params=[],
	) {
		if (!class_exists($class, true)) {
			throw new InvalidArgumentException("{$name} is not a valid class");
		}
	}

	public function setParameters(FunctionParameter ...$params): self {
		$this->params = array_values($params);
		return $this;
	}

	public function setDescription(?string $description): self {
		$this->description = $description;
		return $this;
	}

	/** @return list<string> */
	public function getSecrets(): array {
		$secrets = [];
		foreach ($this->params as $param) {
			if ($param->type === $param::TYPE_SECRET) {
				$secrets []= $param->name;
			}
		}
		return $secrets;
	}
}
