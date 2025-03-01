<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use BackedEnum;
use InvalidArgumentException;
use Stringable;

/**
 * This is a bit field that consists of a set of flags from an enum
 *
 * @template T of BackedEnum
 */
class EnumBitfield extends Bitfield implements Stringable {
	/** @var list<BackedEnum> */
	private array $flags = [];
	private int $value = 0;

	/** @param class-string<T> $class */
	public function __construct(private string $class) {
	}

	public function __toString(): string {
		return implode('|', array_map(static fn (BackedEnum $e): string => $e->name, $this->flags));
	}

	/** Check if this bit field has the given flag(s) all set */
	public function has(int|BackedEnum $flag): bool {
		$intFlag = is_int($flag) ? $flag : (int)$flag->value;
		return ($this->value & $intFlag) === $intFlag;
	}

	/** Check if this bit field has the given flag(s) all set */
	public function hasAll(int|BackedEnum ...$flags): bool {
		foreach ($flags as $flag) {
			$flag = is_int($flag) ? $flag : (int)$flag->value;
			if (($this->value & $flag) !== $flag) {
				return false;
			}
		}
		return true;
	}

	/** Check if this bit field has any of the given flag(s) set */
	public function hasAny(int|BackedEnum ...$flags): bool {
		foreach ($flags as $flag) {
			$flag = is_int($flag) ? $flag : (int)$flag->value;
			if (($this->value & $flag) !== 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Set some bits by integer value if they exist as enum
	 *
	 * @return self<T>
	 */
	public function setInt(int $value): self {
		$class = $this->class;
		foreach ($class::cases() as $case) {
			if (((int)$case->value & $value) !== 0) {
				$this->set($case);
			}
		}
		return $this;
	}

	/**
	 * Set some bits by integer or enum value (if the integers exist as enum)
	 *
	 * @return self<T>
	 */
	public function set(BackedEnum|int ...$flags): self {
		$class = $this->class;
		for ($i = 0; $i < count($flags); $i++) {
			if (!is_int($flags[$i]) && !is_a($flags[$i], $this->class, false)) {
				$i++;
				throw new InvalidArgumentException(
					__CLASS__ . '::' . __FUNCTION__ . "(): Argument #{$i} must be a {$this->class}"
				);
			}
			$value = is_int($flags[$i]) ? $flags[$i] : (int)$flags[$i]->value;
			if (($this->value & $value) !== $value) {
				$this->value |= $value;
				if (is_int($flags[$i])) {
					$this->flags []= $class::from($flags[$i]);
				} else {
					$this->flags []= $flags[$i];
				}
			}
		}
		return $this;
	}

	/** Get a numeric representation of this bit field */
	public function toInt(): int {
		return $this->value;
	}
}
