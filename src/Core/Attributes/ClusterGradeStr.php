<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Nadybot\Modules\IMPLANT_MODULE\ClusterGrade;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ClusterGradeStr extends AbstractParamAttribute {
	public function getRegexp(): string {
		return implode('|', array_map(static fn (ClusterGrade $grade): string => $grade->value, ClusterGrade::cases()));
	}
}
