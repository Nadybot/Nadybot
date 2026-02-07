<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\StringableTrait;

class CompletionCommand {
	use StringableTrait;

	/**
	 * @param Message[] $messages
	 *
	 * @psalm-param non-empty-list<Message> $messages
	 */
	public function __construct(
		public readonly string $model,
		#[CastListToType(Message::class)] public readonly array $messages,
		public readonly float $temperature=0.7,
	) {
	}
}
