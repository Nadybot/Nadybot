<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** The dimensions (servers) the bot can run on */
enum Dimension: int {
	/**
	 * The dimensions run by Funcom
	 *
	 * Kept as plain integers, so it can be used in constant expressions
	 *
	 * @var list<int>
	 */
	public const FUNCOM_DIMENSIONS = [4, 5, 6];

	/** Get the name of this dimension to show to users */
	public function getName(): string {
		return match ($this) {
			self::Test => 'Test',
			self::RK5 => 'RK5',
			self::RK19 => 'RK19',
			self::LostEden => 'Lost Eden',
		};
	}

	/** Get a longer description of this dimension, e.g. for the setup */
	public function getDescription(): string {
		return match ($this) {
			self::Test => 'Test server',
			self::RK5 => 'Rubi-Ka (a.k.a. RK5)',
			self::RK19 => 'RK2019 (a.k.a. RK19)',
			self::LostEden => 'Lost Eden (private server)',
		};
	}

	/** Get the suffix to append to character names when relaying across dimensions */
	public function getRelaySuffix(): string {
		return match ($this) {
			self::Test, self::RK5, self::RK19 => $this->getName(),
			self::LostEden => 'LostEden',
		};
	}

	/** Check if this dimension is run by Funcom */
	public function isFuncom(): bool {
		return in_array($this->value, self::FUNCOM_DIMENSIONS, true);
	}

	/**
	 * Get the name of $dimension to show to users
	 *
	 * Dimensions the bot cannot run on are called "RK<number>", with the
	 * name of the server added for the long gone RK1-RK3
	 */
	public static function nameOf(int $dimension): string {
		return self::tryFrom($dimension)?->getName() ?? match ($dimension) {
			1 => 'RK1 (Atlantean)',
			2 => 'RK2 (Rimor)',
			3 => 'RK3 (Die Neue Welt)',
			default => "RK{$dimension}",
		};
	}

	/** Check if $dimension is run by Funcom */
	public static function isFuncomDimension(int $dimension): bool {
		return self::tryFrom($dimension)?->isFuncom() ?? false;
	}

	case Test = 4;
	case RK5 = 5;
	case RK19 = 6;
	case LostEden = 7;
}
