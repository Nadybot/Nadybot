<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use ValueError;

/** This is the representation of a valid implant/symbiant slot */
enum ImplantSlot: int implements EnumParameterInterface {
	/** @inheritDoc */
	public static function fromParam(string $param): self {
		return self::fromName($param);
	}

	/** Create an instance based on one of the many variations of its name */
	public static function fromName(string $name): self {
		return match (strtolower($name)) {
			'eye','eyes','ocular' => self::Eye,
			'head','brain' => self::Head,
			'ear' => self::Ear,
			'right arm','rarm' => self::RightArm,
			'body','chest' => self::Chest,
			'left arm','larm' => self::LeftArm,
			'right wrist','rwrist' => self::RightWrist,
			'waist' => self::Waist,
			'left wrist','lwrist' => self::LeftWrist,
			'right hand','rhand' => self::RightHand,
			'legs','leg','thigh' => self::Leg,
			'left hand','lhand' => self::LeftHand,
			'foot','feet' => self::Feet,
			default => throw new ValueError("Unknown implant slot name '{$name}'"),
		};
	}

	/**
	 * Try to create an instance based on one of the many variations of its name,
	 * or return null if the name doesn't match any known slot.
	 */
	public static function tryFromName(string $name): ?self {
		try {
			return static::fromName($name);
		} catch (ValueError) {
			return null;
		}
	}

	/** Create a new instance, purely based on the slot name of the implant designer */
	public static function fromDesignSlotName(string $name): self {
		return match (strtolower($name)) {
			'eye' => self::Eye,
			'head' => self::Head,
			'ear' => self::Ear,
			'rarm' => self::RightArm,
			'chest' => self::Chest,
			'larm' => self::LeftArm,
			'rwrist' => self::RightWrist,
			'waist' => self::Waist,
			'lwrist' => self::LeftWrist,
			'rhand' => self::RightHand,
			'legs' => self::Leg,
			'lhand' => self::LeftHand,
			'feet' => self::Feet,
			default => throw new ValueError("Unknown implant slot name '{$name}'"),
		};
	}

	/** @inheritDoc */
	public static function getParamRegexp(): string {
		return 'eyes?|ocular'.
		'|head|brain'.
		'|ear'.
		'|right arm|rarm'.
		'|body|chest'.
		'|left arm|larm'.
		'|right wrist|rwrist'.
		'|waist'.
		'|left wrist|lwrist'.
		'|right hand|rhand'.
		'|legs|leg|thigh'.
		'|left hand|lhand'.
		'|foot|feet';
	}

	/** Create an instance based on the implant designer type id (1 to 13) */
	public static function fromTypeID(int $type): self {
		return match ($type) {
			1 => self::Eye,
			2 => self::Head,
			3 => self::Ear,
			4 => self::Chest,
			5 => self::Waist,
			6 => self::Leg,
			7 => self::Feet,
			8 => self::LeftArm,
			9 => self::LeftWrist,
			10 => self::LeftHand,
			11 => self::RightArm,
			12 => self::RightWrist,
			13 => self::RightHand,
			default => throw new ValueError("Unknown implant type id '{$type}'"),
		};
	}

	/** Get the implant designer type id of this slot */
	public function typeId(): int {
		return match ($this) {
			self::Eye => 1,
			self::Head => 2,
			self::Ear => 3,
			self::Chest => 4,
			self::Waist => 5,
			self::Leg => 6,
			self::Feet => 7,
			self::LeftArm => 8,
			self::LeftWrist => 9,
			self::LeftHand => 10,
			self::RightArm => 11,
			self::RightWrist => 12,
			self::RightHand => 13,
		};
	}

	/**
	 * Return the name of the slot as needed by the implant designer
	 *
	 * @psalm-return 'eye'|'head'|'ear'|'rarm'|'chest'|'larm'|'rwrist'|'waist'|'lwrist'|'rhand'|'legs'|'lhand'|'feet'
	 */
	public function designSlotName(): string {
		return match ($this) {
			self::Eye => 'eye',
			self::Head => 'head',
			self::Ear => 'ear',
			self::RightArm => 'rarm',
			self::Chest => 'chest',
			self::LeftArm => 'larm',
			self::RightWrist => 'rwrist',
			self::Waist => 'waist',
			self::LeftWrist => 'lwrist',
			self::RightHand => 'rhand',
			self::Leg => 'legs',
			self::LeftHand => 'lhand',
			self::Feet => 'feet',
		};
	}

	/** Return the long name (Right Arm, Ocular, …) of the implant slot */
	public function longName(): string {
		return match ($this) {
			self::Eye => 'Ocular',
			self::Head => 'Brain',
			self::Ear => 'Ear',
			self::RightArm => 'Right Arm',
			self::Chest => 'Chest',
			self::LeftArm => 'Left Arm',
			self::RightWrist => 'Right Wrist',
			self::Waist => 'Waist',
			self::LeftWrist => 'Left Wrist',
			self::RightHand => 'Right Hand',
			self::Leg => 'Thigh',
			self::LeftHand => 'Left Hand',
			self::Feet => 'Feet',
		};
	}

	case Eye = 2;
	case Head = 4;
	case Ear = 8;
	case RightArm = 16;
	case Chest = 32;
	case LeftArm = 64;
	case RightWrist = 128;
	case Waist = 256;
	case LeftWrist = 512;
	case RightHand = 1_024;
	case Leg = 2_048;
	case LeftHand = 4_096;
	case Feet = 8_192;
}
