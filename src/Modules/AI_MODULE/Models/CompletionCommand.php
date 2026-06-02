<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

class CompletionCommand {
	use StringableTrait;

	/**
	 * @param list<\stdClass> $messages
	 * @param Tool[]          $tools
	 *
	 * @psalm-param non-empty-list<\stdClass> $messages
	 * @psalm-param list<Tool> $tools
	 */
	public function __construct(
		public readonly string $model,
		public readonly array $messages,
		public readonly float $temperature=0.7,
		public array $tools=[],
	) {
	}
}
