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

	/** Check if we have any cluster or symbiant set */
	public function isEmpty(): bool {
		return !isset($this->shiny)
			&& !isset($this->bright)
			&& !isset($this->faded)
			&& !isset($this->symb);
	}

	/** Check if the slot has a given cluster grade set */
	public function has(ClusterGrade $grade): bool {
		return $this->get($grade) !== null;
	}

	/** Check if the slot has a given cluster grade set */
	public function get(ClusterGrade $grade): ?string {
		return match ($grade) {
			ClusterGrade::Shiny => $this->shiny,
			ClusterGrade::Bright => $this->bright,
			ClusterGrade::Faded => $this->faded,
		};
	}

	/** Set a cluster slot to a given value */
	public function set(ClusterGrade $grade, ?string $value): ?string {
		return match ($grade) {
			ClusterGrade::Shiny => $this->shiny = $value,
			ClusterGrade::Bright => $this->bright = $value,
			ClusterGrade::Faded => $this->faded = $value,
		};
	}
}
