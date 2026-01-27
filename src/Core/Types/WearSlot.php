<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use ValueError;

/** This is a valid slot in which to wear armor */
enum WearSlot: int {
	/**
	 * Create a new bit field of wear slots, based on the given name
	 *
	 * @return EnumBitfield<WearSlot>
	 */
	public static function fromName(string $name): EnumBitfield {
		$result = new EnumBitfield(self::class);
		return match (strtolower($name)) {
			'neck' => $result->set(self::Neck),
			'head','helm','helmet','specialhelmet' => $result->set(self::Head),
			'back' => $result->set(self::Back),
			'rightshoulder','right shoulder','rshoulder' => $result->set(self::RightShoulder),
			'leftshoulder','left shoulder','lshoulder' => $result->set(self::LeftShoulder),
			'shoulder','shoulders' => $result->set(self::RightShoulder, self::LeftShoulder),
			'body','chest' => $result->set(self::Body),
			'rightarm','right arm','rarm','right sleeve','rsleeve' => $result->set(self::RightArm),
			'leftarm','left arm','larm','left sleeve','lsleeve' => $result->set(self::LeftArm),
			'arm','arms','sleeve','sleeves' => $result->set(self::RightArm, self::LeftArm),
			'hand','hands','glove','gloves','gauntlet','gauntlets' => $result->set(self::Hands),
			'rightwrist','right wrist','rwrist' => $result->set(self::RightWrist),
			'leftwrist','left wrist','lwrist' => $result->set(self::LeftWrist),
			'wrist','wrists' => $result->set(self::RightWrist, self::LeftWrist),
			'rightfinger','right finger','rfinger' => $result->set(self::RightFinger),
			'leftfinger','left finger','lfinger' => $result->set(self::LeftFinger),
			'finger','fingers','ring' => $result->set(self::RightFinger, self::LeftFinger),
			'leg','legs','pant','pants' => $result->set(self::Legs),
			'foot','feet','boots','booy' => $result->set(self::Feet),
			default => throw new ValueError("Unknown armor slot name '{$name}'"),
		};
	}

	/** Return the long name (Right Arm, Ocular, …) of the wear slot */
	public function longName(): string {
		return match ($this) {
			self::Neck => 'Neck',
			self::Head => 'Head',
			self::Back => 'Back',
			self::RightShoulder => 'Right Shoulder',
			self::Body => 'Body',
			self::LeftShoulder => 'Left Shoulder',
			self::RightArm => 'Right Arm',
			self::Hands => 'Hands',
			self::LeftArm => 'Left Arm',
			self::RightWrist => 'Right Wrist',
			self::Legs => 'Legs',
			self::LeftWrist => 'Left Wrist',
			self::RightFinger => 'Right Finger',
			self::Feet => 'Feet',
			self::LeftFinger => 'Left Finger',
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
