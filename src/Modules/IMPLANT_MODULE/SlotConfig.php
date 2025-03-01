<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use EventSauce\ObjectHydrator\DoNotSerialize;
use Nadybot\Core\Types\Skill;

class SlotConfig {
	/** @psalm-param ?int<1,300> $ql */
	public function __construct(
		public ?Skill $shiny=null,
		public ?Skill $bright=null,
		public ?Skill $faded=null,
		public ?SymbiantSlot $symb=null,
		public ?int $ql=null,
	) {
	}

	/** Check if we have any cluster or symbiant set */
	#[DoNotSerialize]
	public function isEmpty(): bool {
		return !isset($this->shiny)
			&& !isset($this->bright)
			&& !isset($this->faded)
			&& !isset($this->symb);
	}

	/** Check if the slot has a given cluster grade set */
	#[DoNotSerialize]
	public function has(ClusterGrade $grade): bool {
		return $this->get($grade) !== null;
	}

	/** Check if the slot has a given cluster grade set */
	#[DoNotSerialize]
	public function get(ClusterGrade $grade): ?Skill {
		return match ($grade) {
			ClusterGrade::Shiny => $this->shiny,
			ClusterGrade::Bright => $this->bright,
			ClusterGrade::Faded => $this->faded,
		};
	}

	/** Set a cluster slot to a given value */
	#[DoNotSerialize]
	public function set(ClusterGrade $grade, ?Skill $skill): ?Skill {
		return match ($grade) {
			ClusterGrade::Shiny => $this->shiny = $skill,
			ClusterGrade::Bright => $this->bright = $skill,
			ClusterGrade::Faded => $this->faded = $skill,
		};
	}
}
