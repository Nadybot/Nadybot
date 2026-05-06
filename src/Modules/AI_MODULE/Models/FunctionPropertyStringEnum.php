<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

class FunctionPropertyStringEnum extends FunctionPropertyString {
	use StringableTrait;

	/** @psalm-param non-empty-list<string> $enum Set of allowed values */
	public function __construct(
		string $description,
		public readonly array $enum,
	) {
		parent::__construct(description: $description);
	}
}
