<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\EnumParameterInterface;
use ValueError;

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

	public static function fromId(int $id): self {
		return match ($id) {
			1 => self::Faded,
			2 => self::Bright,
			3 => self::Shiny,
			default => throw new ValueError("Unknown cluster grade id \"{$id}\""),
		};
	}

	public function getId(): int {
		return match ($this) {
			self::Faded => 1,
			self::Bright => 2,
			self::Shiny => 3,
		};
	}

	case Shiny = 'shiny';
	case Bright = 'bright';
	case Faded = 'faded';
}
