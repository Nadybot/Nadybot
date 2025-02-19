<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use InvalidArgumentException;

/** This represents a valid profession */
enum Profession: string implements EnumParameterInterface {
	/** @inheritDoc */
	public static function getParamRegexp(): string {
		return 'adv(|y|enturer)'.
		'|age(nt)?'.
		'|(bureau)?crat'.
		'|doc(tor)?'.
		'|enf(o|orcer)?'.
		'|eng([iy]|ineer)?'.
		'|fix(er)?'.
		'|keep(er)?'.
		'|ma(rtial( ?artist)?)?'.
		'|mp|meta(-?physicist)?'.
		'|nt|nano(-?technician)?'.
		'|sol(d|dier)?'.
		'|tra(d|der)?'.
		'|sha(de)?';
	}

	/** Get the numeric representation of this profession (1-12,14-15) */
	public function toNumber(): int {
		return match ($this) {
			self::Adventurer => 6,
			self::Agent => 5,
			self::Bureaucrat => 8,
			self::Doctor => 10,
			self::Enforcer => 9,
			self::Engineer => 3,
			self::Fixer => 4,
			self::Keeper => 14,
			self::MartialArtist => 2,
			self::MetaPhysicist => 12,
			self::NanoTechnician => 11,
			self::Shade => 15,
			self::Soldier => 1,
			self::Trader => 7,
			self::Unknown => 0,
		};
	}

	/**
	 * Get the short form
	 *
	 * Adventurer becomes Adv, etc.
	 */
	public function short(): string {
		return match ($this) {
			self::Adventurer => 'Adv',
			self::Agent => 'Agent',
			self::Bureaucrat => 'Crat',
			self::Doctor => 'Doc',
			self::Enforcer => 'Enf',
			self::Engineer => 'Eng',
			self::MartialArtist => 'MA',
			self::MetaPhysicist => 'MP',
			self::NanoTechnician => 'NT',
			self::Soldier => 'Sol',
			self::Fixer => 'Fixer',
			self::Trader => 'Trader',
			self::Keeper => 'Keeper',
			self::Shade => 'Shade',
			self::Unknown => 'Unknown',
		};
	}

	/**
	 * Get a list of all of the professions' short names
	 *
	 * @return list<string>
	 */
	public static function shortNames(): array {
		return [
			'Adv', 'Agent', 'Crat', 'Doc', 'Enf', 'Eng', 'Fix', 'Keep',
			'MA', 'MP', 'NT', 'Sol', 'Shade', 'Trader',
		];
	}

	/** Get the colorized string for this profession */
	public function inColor(): string {
		return "<highlight>{$this->value}<end>";
	}

	/** Get a HTML <img> tag this displays this profession's icon */
	public function toIcon(): string {
		return '<img src=tdb://id:GFX_GUI_ICON_PROFESSION_'.$this->toNumber().'>';
	}

	/** Try to create an instance based on the many short or long names of the professions */
	public static function tryFromName(string $search): ?self {
		try {
			return self::fromName($search);
		} catch (\Throwable) {
			return null;
		}
	}

	/** Create an instance based on the many short or long names of the professions */
	public static function fromName(string $search): self {
		return match (strtolower($search)) {
			'adv','advy','adventurer' => self::Adventurer,
			'age','agent' => self::Agent,
			'crat','bureaucrat' => self::Bureaucrat,
			'doc','doctor' => self::Doctor,
			'enf','enfo','enforcer' => self::Enforcer,
			'eng','engi','engy','engineer' => self::Engineer,
			'fix','fixer' => self::Fixer,
			'keep','keeper' => self::Keeper,
			'ma','martial','martialartist','martial artist' => self::MartialArtist,
			'mp','meta','metaphysicist','meta-physicist' => self::MetaPhysicist,
			'nt','nano','nanotechnician','nano-technician' => self::NanoTechnician,
			'sol','sold','soldier' => self::Soldier,
			'tra','trad','trader' => self::Trader,
			'sha','shade' => self::Shade,
			default => throw new InvalidArgumentException("Invalid profession '{$search}'"),
		};
	}

	/** @inheritDoc */
	public static function fromParam(string $param): self {
		return self::fromName($param);
	}

	/** Create an instance based on the numeric value */
	public static function fromNumber(int $search): self {
		return match ($search) {
			0 => self::Unknown,
			1 => self::Soldier,
			2 => self::MartialArtist,
			3 => self::Engineer,
			4 => self::Fixer,
			5 => self::Agent,
			6 => self::Adventurer,
			7 => self::Trader,
			8 => self::Bureaucrat,
			9 => self::Enforcer,
			10 => self::Doctor,
			11 => self::NanoTechnician,
			12 => self::MetaPhysicist,
			14 => self::Keeper,
			15 => self::Shade,
			default => throw new InvalidArgumentException("Invalid profession '{$search}'"),
		};
	}

	/** Try to create an instance based on the numeric value, return null if invalid */
	public static function tryFromNumber(?int $search): ?self {
		try {
			if (!isset($search)) {
				return null;
			}
			return self::fromNumber($search);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Check if the given string matches the profession (abbreviated or not)
	 * Only use for user input, directly check against the enum otherwise
	 *
	 * @example One: $this->is('engi')
	 * @example Two: $this->is($prof)
	 */
	public function is(string $search): bool {
		return self::tryFromName($search) === $this;
	}

	case Adventurer = 'Adventurer';
	case Agent = 'Agent';
	case Bureaucrat = 'Bureaucrat';
	case Doctor = 'Doctor';
	case Enforcer = 'Enforcer';
	case Engineer = 'Engineer';
	case Fixer = 'Fixer';
	case Keeper = 'Keeper';
	case MartialArtist = 'Martial Artist';
	case MetaPhysicist = 'Meta-Physicist';
	case NanoTechnician = 'Nano-Technician';
	case Shade = 'Shade';
	case Soldier = 'Soldier';
	case Trader = 'Trader';
	case Unknown = 'Unknown';
}
