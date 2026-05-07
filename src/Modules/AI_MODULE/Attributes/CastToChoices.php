<?php

declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Attributes;

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

	/** @return list<object> */
	public function cast(mixed $value, ObjectMapper $hydrator): array {
		assert(is_array($value), 'value is expected to be an array');
		assert(array_is_list($value), 'value is not expected to be a list');

		for ($i = 0; $i < count($value); $i++) {
			assert(is_array($value[$i]), 'value is expected to be an array');
			if (!array_key_exists($this->key, $value[$i])) {
				throw new \Exception("Key \"{$this->key}\" does not exist.");
			}
			$key = $value[$i][$this->key];
			$targetClass = null;
			if (is_string($key) &&array_key_exists($key, $this->mapping)) {
				$targetClass = $this->mapping[$key];
			} elseif (array_key_exists('*', $this->mapping)) {
				$targetClass = $this->mapping['*'];
			} else {
				throw new \Exception("\"{$this->key}={$key}\" is unsupported.");
			}
			$value[$i] = $hydrator->hydrateObject($targetClass, $value[$i]);
		}

		/** @var list<object> $value */
		return $value;
	}
}
