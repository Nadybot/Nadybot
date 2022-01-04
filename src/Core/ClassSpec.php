<?php declare(strict_types=1);

namespace Nadybot\Core;

use InvalidArgumentException;

class ClassSpec {
	public string $name;

	/** @phpstan-var class-string */
	public string $class;

	/** @var FunctionParameter[] */
	public array $params = [];

	public ?string $description = null;

	/** @phpstan-param class-string $name */
	public function __construct(string $name, string $class) {
		$this->name = $name;
		if (!class_exists($class)) {
			throw new InvalidArgumentException("{$name} is not a valid class");
		}
		$this->class = $class;
	}

	public function setParameters(FunctionParameter ...$params): self {
		$this->params = $params;
		return $this;
	}

	public function setDescription(?string $description): self {
		$this->description = $description;
		return $this;
	}

	/** @return string[] */
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
