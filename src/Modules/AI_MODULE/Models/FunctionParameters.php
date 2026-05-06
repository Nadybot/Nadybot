<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

class FunctionParameters {
	use StringableTrait;
	public readonly string $type;

	/**
	 * @param array<string,FunctionProperty> $properties
	 * @param string[]                       $required
	 *
	 * @psalm-param list<string> $required
	 */
	public function __construct(
		public readonly array $properties,
		public readonly array $required=[],
	) {
		$this->type = 'object';
	}
}
