<?php declare(strict_types=1);

namespace Nadybot\Core;

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
			self::Soldier => 1,
			self::Shade => 15,
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
			self::Bureaucrat => 'Crat',
			self::Doctor => 'Doc',
			self::Enforcer => 'Enf',
			self::Engineer => 'Eng',
			self::MartialArtist => 'MA',
			self::MetaPhysicist => 'MP',
			self::NanoTechnician => 'NT',
			self::Soldier => 'Sol',
			default => $this->name,
		};
	}

	public function inColor(): string {
		return '<highlight>' . $this->value . '<end>';
	}

	public function toIcon(): string {
		return '<img src=tdb://id:GFX_GUI_ICON_PROFESSION_'.$this->toNumber().'>';
	}

	public static function byName(string $search): self {
		$search = strtolower($search);
		switch ($search) {
			case 'adv':
			case 'advy':
			case 'adventurer':
				return self::Adventurer;
			case 'age':
			case 'agent':
				return self::Agent;
			case 'crat':
			case 'bureaucrat':
				return self::Bureaucrat;
			case 'doc':
			case 'doctor':
				return self::Doctor;
			case 'enf':
			case 'enfo':
			case 'enforcer':
				return self::Enforcer;
			case 'eng':
			case 'engi':
			case 'engy':
			case 'engineer':
				return self::Engineer;
			case 'fix':
			case 'fixer':
				return self::Fixer;
			case 'keep':
			case 'keeper':
				return self::Keeper;
			case 'ma':
			case 'martial':
			case 'martialartist':
			case 'martial artist':
				return self::MartialArtist;
			case 'mp':
			case 'meta':
			case 'metaphysicist':
			case 'meta-physicist':
				return self::MetaPhysicist;
			case 'nt':
			case 'nano':
			case 'nanotechnician':
			case 'nano-technician':
				return self::NanoTechnician;
			case 'sol':
			case 'sold':
			case 'soldier':
				return self::Soldier;
			case 'tra':
			case 'trad':
			case 'trader':
				return self::Trader;
			case 'sha':
			case 'shade':
				return self::Shade;
			default:
				throw new InvalidArgumentException("Invalid profession '{$search}'");
		}
	}

	/** Check if the given string matches the profession (abbreviated or not) */
	public function is(string $search): bool {
		$search = strtolower($search);
		switch ($search) {
			case 'adv':
			case 'advy':
			case 'adventurer':
				return $this === self::Adventurer;
			case 'age':
			case 'agent':
				return $this === self::Agent;
			case 'crat':
			case 'bureaucrat':
				return $this === self::Bureaucrat;
			case 'doc':
			case 'doctor':
				return $this === self::Doctor;
			case 'enf':
			case 'enfo':
			case 'enforcer':
				return $this === self::Enforcer;
			case 'eng':
			case 'engi':
			case 'engy':
			case 'engineer':
				return $this === self::Engineer;
			case 'fix':
			case 'fixer':
				return $this === self::Fixer;
			case 'keep':
			case 'keeper':
				return $this === self::Keeper;
			case 'ma':
			case 'martial':
			case 'martialartist':
			case 'martial artist':
				return $this === self::MartialArtist;
			case 'mp':
			case 'meta':
			case 'metaphysicist':
			case 'meta-physicist':
				return $this === self::MetaPhysicist;
			case 'nt':
			case 'nano':
			case 'nanotechnician':
			case 'nano-technician':
				return $this === self::NanoTechnician;
			case 'sol':
			case 'sold':
			case 'soldier':
				return $this === self::Soldier;
			case 'tra':
			case 'trad':
			case 'trader':
				return $this === self::Trader;
			case 'sha':
			case 'shade':
				return $this === self::Shade;
			default:
				return false;
		}
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
