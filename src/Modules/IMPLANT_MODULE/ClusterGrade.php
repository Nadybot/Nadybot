<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

enum ClusterGrade: string {
	public function cmp(self $target): int {
		$myPos = array_search($this, self::cases(), true);
		$otherPos = array_search($target, self::cases(), true);
		return $myPos <=> $otherPos;
	}

	case Shiny = 'shiny';
	case Bright = 'bright';
	case Faded = 'faded';
}
