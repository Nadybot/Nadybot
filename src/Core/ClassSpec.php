<?php declare(strict_types=1);

namespace Nadybot\Core;

class ClassSpec {
	public string $name;

	public string $class;

	/** @var FunctionParameter[] */
	public array $params;
	
	public ?string $description = null;

	public function __construct(string $name, string $class) {
		$this->name = $name;
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
}
