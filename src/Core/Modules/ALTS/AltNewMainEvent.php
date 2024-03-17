<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

class AltNewMainEvent extends AltEvent {
	public function __construct(
		public string $main,
		public string $alt,
		public ?bool $validated,
	) {
		$this->type = "alt(newmain)";
	}
}
