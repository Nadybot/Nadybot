<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

class SlotConfig {
	/** @psalm-param ?int<1,300> $ql */
	public function __construct(
		public ?string $shiny=null,
		public ?string $bright=null,
		public ?string $faded=null,
		public ?SymbiantSlot $symb=null,
		public ?int $ql=null,
	) {
	}
}
