<?php declare(strict_types=1);

namespace Nadybot\Core;

/** The name and specs of a function parameter */
class FunctionParameter {
	public const TYPE_SECRET = 'secret';
	public const TYPE_STRING = 'string';
	public const TYPE_STRING_ARRAY = 'string[]';
	public const TYPE_INT = 'int';
	public const TYPE_BOOL = 'bool';

	public function __construct(
		public string $name,
		public string $type,
		public ?string $description=null,
		public bool $required=true,
	) {
	}
}
