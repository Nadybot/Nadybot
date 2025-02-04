<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\EnumParameterInterface;

enum ClusterGrade: string implements EnumParameterInterface {
	public static function getParamRegexp(): string {
		return 'shiny|bright|faded';
	}

	public static function fromParam(string $param): self {
		return self::from(strtolower($param));
	}

	public function cmp(self $target): int {
		$myPos = array_search($this, self::cases(), true);
		$otherPos = array_search($target, self::cases(), true);
		return $myPos <=> $otherPos;
	}

	case Shiny = 'shiny';
	case Bright = 'bright';
	case Faded = 'faded';
}
