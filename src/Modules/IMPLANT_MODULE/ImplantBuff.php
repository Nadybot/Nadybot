<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\Ability;

enum ImplantBuff {
	public static function fromName(string $name): self {
		$name = strtolower($name);

		if (in_array($name, ['treat', 'treatment'], true)) {
			return self::Skill;
		}

		if ($name === 'ability') {
			return self::Ability;
		}

		$_ = Ability::fromShort($name)->name;
		return self::Ability;
	}

	case Ability;
	case Skill;
}
