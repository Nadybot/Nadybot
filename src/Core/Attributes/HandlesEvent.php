<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use InvalidArgumentException;
use Nadybot\Core\Types\Status;

/** This method should be called whenever the given event occurs */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class HandlesEvent {
	/** @param null|string|list<string> $mask */
	public function __construct(
		public null|string|array $mask=null,
		public ?string $help=null,
		public ?Status $defaultStatus=null,
	) {
		if (!isset($mask)) {
			return;
		}
		foreach ((array)$mask as $checkMask) {
			if (str_contains($checkMask, ' ')) {
				throw new InvalidArgumentException('Event mask cannot contain spaces');
			}
		}
	}
}
