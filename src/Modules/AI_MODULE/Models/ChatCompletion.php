<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use DateTimeInterface;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\StringableTrait;

/**
 * Represents a chat completion response returned by model, based on
 * the provided input.
 */
class ChatCompletion {
	use StringableTrait;

	/**
	 * @param string            $id                 A unique identifier for the chat completion.
	 * @param DateTimeInterface $created            The Unix timestamp (in seconds) of when the chat completion was created.
	 * @param string            $model              The model used for the chat completion.
	 * @param Choice[]          $choices            A list of chat completion choices. Can be more than one if `n` is
	 *                                              greater than 1.
	 * @param string            $object             The object type, which is always "chat.completion".
	 * @param ?string           $system_fingerprint This fingerprint represents the backend configuration that the model
	 *                                              runs with.
	 *                                              Can be used in conjunction with the `seed` request parameter to
	 *                                              understand when backend changes have been made that might impact
	 *                                              determinism.
	 *
	 * @psalm-param non-empty-list<Choice> $choices
	 */
	public function __construct(
		public readonly string $id,
		public readonly DateTimeInterface $created,
		public readonly string $model,
		#[CastListToType(Choice::class)] public readonly array $choices,
		public readonly string $object='chat.completion',
		public readonly ?string $system_fingerprint=null,
	) {
	}
}
