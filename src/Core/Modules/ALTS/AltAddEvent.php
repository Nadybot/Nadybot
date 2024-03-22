<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

class AltAddEvent extends AltEvent {
	public const EVENT_MASK = 'alt(add)';

	public function __construct(
		public string $main,
		public string $alt,
		public ?bool $validated,
	) {
		$this->type = self::EVENT_MASK;
	}
}
