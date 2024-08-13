<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use InvalidArgumentException;

enum Profession: string {
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

	/** @return list<string>  */
	public static function shortNames(): array {
		return [
			'Adv', 'Agent', 'Crat', 'Doc', 'Enf', 'Eng', 'Fix', 'Keep',
			'MA', 'MP', 'NT', 'Sol', 'Shade', 'Trader',
		];
	}

	public function inColor(): string {
		return "<highlight>{$this->value}<end>";
	}

	public function toIcon(): string {
		return '<img src=tdb://id:GFX_GUI_ICON_PROFESSION_'.$this->toNumber().'>';
	}

	public static function tryByName(string $search): ?self {
		try {
			return self::byName($search);
		} catch (\Throwable) {
			return null;
		}
	}

	public static function byName(string $search): self {
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

	public static function byNumber(int $search): self {
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

	public static function tryByNumber(?int $search): ?self {
		try {
			if (!isset($search)) {
				return null;
			}
			return self::byNumber($search);
		} catch (\Throwable) {
			return null;
		}
	}

	/** Check if the given string matches the profession (abbreviated or not) */
	public function is(string $search): bool {
		return self::tryByName($search) === $this;
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
