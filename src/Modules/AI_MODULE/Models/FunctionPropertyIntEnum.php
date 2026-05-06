<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

class FunctionPropertyIntEnum extends FunctionPropertyInt {
	use StringableTrait;

	/** @psalm-param non-empty-list<int> $enum Set of allowed values */
	public function __construct(
		string $description,
		public readonly array $enum,
	) {
		parent::__construct(description: $description);
	}
}
