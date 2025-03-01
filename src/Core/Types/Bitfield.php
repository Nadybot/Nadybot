<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Stringable;

/** A bit field represent a set of flags in a single integer value */
class Bitfield implements Stringable {
	/** The internal integer value of all the bits combined */
	private int $value = 0;

	public function __toString(): string {
		return (string)$this->value;
	}

	/** Check if the given flag(s) is/are all set */
	public function has(int $flag): bool {
		return ($this->value & $flag) === $flag;
	}

	/** Check if a list of flags are all set */
	public function hasAll(int ...$flags): bool {
		foreach ($flags as $flag) {
			if (($this->value & $flag) !== $flag) {
				return false;
			}
		}
		return true;
	}

	/** Check if at least one of a list of flags is set */
	public function hasAny(int ...$flags): bool {
		foreach ($flags as $flag) {
			if (($this->value & $flag) === $flag) {
				return true;
			}
		}
		return false;
	}

	/** Set one or more bits */
	public function setInt(int $value): self {
		$this->value |= $value;
		return $this;
	}

	/** Set one or more bits */
	public function set(int ...$flags): self {
		for ($i = 0; $i < count($flags); $i++) {
			$this->value |= $flags[$i];
		}
		return $this;
	}

	/** Get the integer representation of this bit field */
	public function toInt(): int {
		return $this->value;
	}
}
