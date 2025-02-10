<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\Ability;

enum LadderType: string {
	public static function fromName(string $name): self {
		$name = strtolower($name);

		if (in_array($name, ['treat', 'treatment'], true)) {
			return self::Skill;
		}

		if ($name === 'ability') {
			return self::Ability;
		}

		Ability::fromShort($name)->name;
		return LadderType::Ability;
	}

	case Skill = 'skill';
	case Ability = 'ability';
}
