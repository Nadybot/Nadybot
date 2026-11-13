<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** The alien levels with their rank names and the alien experience needed for them */
final class AlienRank {
	/**
	 * Index 0 is AI level 1: [AXP needed to reach it, minimum level, rank name]
	 *
	 * @var list<array{0:int, 1:int, 2:string}>
	 */
	public const LEVELS = [
		[1_500,   5, 'Fledgling'],
		[9_000,  15, 'Amateur'],
		[22_500,  25, 'Beginner'],
		[42_000,  35, 'Starter'],
		[67_500,  45, 'Newcomer'],
		[99_000,  55, 'Student'],
		[136_500,  65, 'Common'],
		[180_000,  75, 'Intermediate'],
		[229_500,  85, 'Mediocre'],
		[285_000,  95, 'Fair'],
		[346_500, 105, 'Able'],
		[414_000, 110, 'Accomplished'],
		[487_500, 115, 'Adept'],
		[567_000, 120, 'Qualified'],
		[697_410, 125, 'Competent'],
		[857_814, 130, 'Suited'],
		[1_055_112, 135, 'Talented'],
		[1_297_787, 140, 'Trustworthy'],
		[1_596_278, 145, 'Supporter'],
		[1_931_497, 150, 'Backer'],
		[2_298_481, 155, 'Defender'],
		[2_689_223, 160, 'Challenger'],
		[3_092_606, 165, 'Patron'],
		[3_494_645, 170, 'Protector'],
		[3_879_056, 175, 'Medalist'],
		[4_228_171, 180, 'Champ'],
		[4_608_707, 185, 'Hero'],
		[5_023_490, 190, 'Guardian'],
		[5_475_604, 195, 'Vanquisher'],
		[5_968_409, 200, 'Vindicator'],
	];

	/** Get the rank name for an alien level (1-30), or an empty string if there is none */
	public static function nameForLevel(?int $aiLevel): string {
		foreach (self::LEVELS as $index => [, , $name]) {
			if ($index + 1 === $aiLevel) {
				return $name;
			}
		}
		return '';
	}
}
