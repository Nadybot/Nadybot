<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Parameter;

use Attribute;
use Nadybot\Modules\IMPLANT_MODULE\ClusterGrade;

/** This string accepts any valid cluster grade (shiny, bright, faded) */
#[Attribute(Attribute::TARGET_PARAMETER)]
class ClusterGradeStr extends AbstractParamAttribute {
	/** @inheritDoc */
	public function getRegexp(): string {
		return implode('|', array_map(static fn (ClusterGrade $grade): string => $grade->value, ClusterGrade::cases()));
	}
}
