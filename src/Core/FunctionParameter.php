<?php declare(strict_types=1);

namespace Nadybot\Core;

class FunctionParameter {
	public const TYPE_STRING = "string";
	public const TYPE_INT = "int";
	public const TYPE_BOOL = "bool";

	public string $name;
	public string $type;
	public ?string $description = null;
	public bool $required=true;
}
