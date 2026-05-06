<?php

declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models\Attributes;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster};

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class CastToChoice implements PropertyCaster {
	/** @param array<string,class-string> $mapping */
	public function __construct(
		private string $key,
		private array $mapping,
	) {
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		assert(is_array($value), 'value is expected to be an array');
		assert(!array_is_list($value), 'value is not expected to be a list');

		if (!array_key_exists($this->key, $value)) {
			throw new \Exception("Key \"{$this->key}\" does not exist.");
		}
		$key = $value[$this->key];
		if (!is_int($key) || !is_string($key) || !array_key_exists($key, $this->mapping)) {
			throw new \Exception("\"{$this->key}={$key}\" is unsupported.");
		}
		return $hydrator->hydrateObject($this->mapping[$key], $value);
	}
}
