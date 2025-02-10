<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use ValueError;

enum CarrySlot: int {
	public function in(int $value): bool {
		return ($value & $this->value) !== 0;
	}

	public function notIn(int $value): bool {
		return ($value & $this->value) === 0;
	}

	/** @return EnumBitfield<CarrySlot> */
	public static function fromName(string $name): EnumBitfield {
		$result = new EnumBitfield(self::class);
		return match (strtolower($name)) {
			'hud1' => $result->set(self::HUD1),
			'hud2' => $result->set(self::HUD2),
			'hud3' => $result->set(self::HUD3),
			'hud' => $result->set(self::HUD1, self::HUD2, self::HUD3),
			'utils1' => $result->set(self::Utils1),
			'utils2' => $result->set(self::Utils2),
			'utils3' => $result->set(self::Utils3),
			'utils' => $result->set(self::Utils1, self::Utils2, self::Utils3),
			'right hand','rhand' => $result->set(self::RightHand),
			'left hand','lhand' => $result->set(self::LeftHand),
			'hand','hands' => $result->set(self::RightHand, self::LeftHand),
			'deck 1','deck1' => $result->set(self::Deck1),
			'deck 2','deck2' => $result->set(self::Deck2),
			'deck 3','deck3' => $result->set(self::Deck3),
			'deck 4','deck4' => $result->set(self::Deck4),
			'deck 5','deck5' => $result->set(self::Deck5),
			'deck 6','deck6' => $result->set(self::Deck6),
			'deck','decks' => $result->set(self::Deck1, self::Deck2, self::Deck3, self::Deck4, self::Deck5, self::Deck6),
			'belt' => $result->set(self::Belt),
			default => throw new ValueError("Unknown carry slot name '{$name}'"),
		};
	}

	case HUD1 = 2;
	case HUD2 = 4;
	case HUD3 = 32_768;
	case Utils1 = 8;
	case Utils2 = 16;
	case Utils3 = 32;
	case RightHand = 64;
	case LeftHand = 256;
	case Belt = 128;
	case Deck1 = 512;
	case Deck2 = 1_024;
	case Deck3 = 2_048;
	case Deck4 = 4_096;
	case Deck5 = 8_192;
	case Deck6 = 16_384;
}
