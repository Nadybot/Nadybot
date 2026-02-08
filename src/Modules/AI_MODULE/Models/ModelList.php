<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\StringableTrait;

/**
 * Represents a response for supported models
 */
class ModelList {
	use StringableTrait;

	/**
	 * @param Model[] $data   A list of supported models.
	 * @param ?string $object The object type, which is always "list".
	 *
	 * @psalm-param list<Model> $data
	 */
	public function __construct(
		#[CastListToType(Model::class)] public readonly array $data,
		public readonly ?string $object='list',
	) {
	}
}
