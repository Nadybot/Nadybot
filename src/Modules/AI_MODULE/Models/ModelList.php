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
	 * @param string  $object The object type, which is always "list".
	 * @param Model[] $data   A list of supported models.
	 *
	 * @psalm-param list<Model> $data
	 */
	public function __construct(
		public readonly string $object,
		#[CastListToType(Model::class)] public readonly array $data,
	) {
	}
}
