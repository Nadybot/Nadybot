<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Core\Types\MessageEmitter;

/** This class emits messages */
#[Attribute(Attribute::IS_REPEATABLE|Attribute::TARGET_CLASS)]
class EmitsMessages implements MessageEmitter {
	public function __construct(
		public string $type,
		public string $name
	) {
	}

	public function getChannelName(): string {
		return "{$this->type}({$this->name})";
	}
}
