<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use ValueError;

enum WearSlot: int {
	/** @return EnumBitfield<WearSlot> */
	public static function fromName(string $name): EnumBitfield {
		$result = new EnumBitfield(self::class);
		return match (strtolower($name)) {
			'neck' => $result->set(self::Neck),
			'head','helm','helmet','specialhelmet' => $result->set(self::Head),
			'back' => $result->set(self::Back),
			'right shoulder','rshoulder' => $result->set(self::RightShoulder),
			'left shoulder','lshoulder' => $result->set(self::LeftShoulder),
			'shoulder','shoulders' => $result->set(self::RightShoulder, self::LeftShoulder),
			'body','chest' => $result->set(self::Body),
			'right arm','rarm','right sleeve','rsleeve' => $result->set(self::RightArm),
			'left arm','larm','left sleeve','lsleeve' => $result->set(self::LeftArm),
			'arm','arms','sleeve','sleeves' => $result->set(self::RightArm, self::LeftArm),
			'right wrist','rwrist' => $result->set(self::RightWrist),
			'left wrist','lwrist' => $result->set(self::LeftWrist),
			'wrist','wrists' => $result->set(self::RightWrist, self::LeftWrist),
			'right finger','rfinger' => $result->set(self::RightFinger),
			'left finger','lfinger' => $result->set(self::LeftFinger),
			'finger','fingers','ring' => $result->set(self::RightFinger, self::LeftFinger),
			'leg','legs','pant','pants' => $result->set(self::Legs),
			'foot','feet','boots','booy' => $result->set(self::Feet),
			default => throw new ValueError("Unknown armor slot name '{$name}'"),
		};
	}

	case Neck = 2;
	case Head = 4;
	case Back = 8;
	case RightShoulder = 16;
	case Body = 32;
	case LeftShoulder = 64;
	case RightArm = 128;
	case Hands = 256;
	case LeftArm = 512;
	case RightWrist = 1_024;
	case Legs = 2_048;
	case LeftWrist = 4_096;
	case RightFinger = 8_192;
	case Feet = 16_384;
	case LeftFinger = 32_768;
}
