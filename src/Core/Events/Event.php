<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\StringableTrait;
use Nadybot\Core\Types\{DoNotSerializePublicFunctions, EventInterface};
use Stringable;

abstract class Event implements Stringable, DoNotSerializePublicFunctions, EventInterface {
	use StringableTrait;

	public function __construct(
		public string $type,
	) {
	}

	public function getEvent(): string {
		return $this->type;
	}

	protected function setEvent(string $type): string {
		return $this->type = $type;
	}
}
