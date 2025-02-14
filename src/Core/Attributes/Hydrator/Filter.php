<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Filter implements PropertyCaster, PropertySerializer {
	public function __construct(
		private int $filter,
		private int $options=0,
		private string $type='',
	) {
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		if (!isset($value)) {
			return null;
		}
		$result = filter_var($value, $this->filter, $this->options);
		if ($result === false) {
			$type = $this->type;
			if (strlen($type) > 0) {
				$type = " for {$type}";
			}
			throw new InvalidArgumentException("\"{$value}\" is not the right format{$type}.");
		}
		return $result;
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		if (!isset($value)) {
			return null;
		}
		$result = filter_var($value, $this->filter, $this->options);
		if ($result === false) {
			$type = $this->type;
			if (strlen($type) > 0) {
				$type = " for {$type}";
			}
			throw new InvalidArgumentException("\"{$value}\" is not the right format{$type}.");
		}
		return $result;
	}
}
