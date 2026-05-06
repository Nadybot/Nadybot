<?php

declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models\Attributes;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster};

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class CastToChoices implements PropertyCaster {
	/** @param array<string,class-string> $mapping */
	public function __construct(
		private string $key,
		private array $mapping,
	) {
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		assert(is_array($value), 'value is expected to be an array');
		assert(array_is_list($value), 'value is not expected to be a list');

		for ($i = 0; $i < count($value); $i++) {
			assert(is_array($value[$i]), 'value is expected to be an array');
			if (!array_key_exists($this->key, $value[$i])) {
				throw new \Exception("Key \"{$this->key}\" does not exist.");
			}
			$key = $value[$i][$this->key];
			$targetClass = null;
			if (array_key_exists($key, $this->mapping)) {
				$targetClass = $this->mapping[$key];
			} elseif (array_key_exists('*', $this->mapping)) {
				$targetClass = $this->mapping['*'];
			} else {
				throw new \Exception("\"{$this->key}={$key}\" is unsupported.");
			}
			$value[$i] = $hydrator->hydrateObject($targetClass, $value[$i]);
		}
		return $value;
	}
}
