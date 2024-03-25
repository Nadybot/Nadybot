<?php declare(strict_types=1);

namespace Nadybot\Core;

enum Government: string {
	/** @return string[] */
	public function getOrgRanks(): array {
		return match ($this) {
			self::Anarchism =>  ['Anarchist'],
			self::Monarchy  =>  ['Monarch',   'Counsil',      'Follower'],
			self::Feudalism =>  ['Lord',      'Knight',       'Vassal',          'Peasant'],
			self::Republic =>   ['President', 'Advisor',      'Veteran',         'Member',         'Applicant'],
			self::Faction =>    ['Director',  'Board Member', 'Executive',       'Member',         'Applicant'],
			self::Department => ['President', 'General',      'Squad Commander', 'Unit Commander', 'Unit Leader', 'Unit Member', 'Applicant'],
		};
	}

	case Anarchism = 'Anarchism';
	case Monarchy = 'Monarchy';
	case Feudalism = 'Feudalism';
	case Republic = 'Republic';
	case Faction = 'Faction';
	case Department = 'Department';
}
