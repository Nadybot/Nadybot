<?php declare(strict_types=1);

namespace Nadybot\Core;

use Stringable;

/** An object representation of a semantic version */
class SemanticVersion implements Stringable {
	/** The normalized version as a string */
	protected string $version;

	/** @param string $origVersion The original, unmodified version */
	public function __construct(protected string $origVersion) {
		$this->version = static::normalizeVersion($origVersion);
	}

	public function __toString(): string {
		return $this->version;
	}

	/** get the unmodified, un-parsed version string */
	public function getOrigVersion(): string {
		return $this->origVersion;
	}

	/**
	 * Compare this version against another version
	 *
	 * @return int * `1`: this version is ranked higher
	 *             * `0`: the two versions are identical
	 *             * `-1`: this version is ranked lower
	 */
	public function cmp(SemanticVersion|string $version2): int {
		return static::compare($this->version, (string)$version2);
	}

	/** Normalize a version string to 3 tuples */
	public static function normalizeVersion(string $version): string {
		$version = Safe::pregReplace('/@.+$/', '', strtolower($version));
		if (Safe::pregMatches("/[^\d]$/", $version)) {
			$version .= '1';
		}
		$version = Safe::pregReplace('/[^a-z0-9.]+/i', '.', $version);
		$version = Safe::pregReplace("/^(\d+)\.(?!\d)/", '$1.0.0.', $version);
		$version = Safe::pregReplace("/^(\d+\.\d+)\.(?!\d)/", '$1.0.', $version);
		return $version;
	}

	/**
	 * Compare 2 version strings
	 *
	 * @return int * `1`: `$version1` is ranked higher than `$version2`
	 *             * `0`: the two versions are identical
	 *             * `-1`: `$version1` is ranked lower than `$version2`
	 */
	public static function compare(string $version1, string $version2): int {
		$v1 = explode('.', static::normalizeVersion($version1));
		$v2 = explode('.', static::normalizeVersion($version2));

		for ($i = 0; $i < max(count($v1), count($v2)); $i++) {
			$t1 = $v1[$i] ?? null;
			$t2 = $v2[$i] ?? null;
			if ($t1 === null) {
				return 1;
			} elseif ($t2 === null) {
				return -1;
			}
			if (!ctype_digit($t1) && !ctype_digit($t2)) {
				if ($t1 === $t2) {
					continue;
				}
				return strcmp($t1, $t2);
			} elseif (!ctype_digit($t1)) {
				return 1;
			} elseif (!ctype_digit($t2)) {
				return -1;
			}
			if (($cmp = $t1 <=> $t2) === 0) {
				continue;
			}
			return $cmp;
		}
		return 0;
	}

	/** Check if `$version` is in range of `$range` */
	public static function inMask(string $range, string $version): bool {
		$version = strtolower($version);
		$range = strtolower($range);
		if (count($matches = Safe::pregMatch('/^(?<operator>[<>!=~^]+)(?<version>[0-9a-z.-]+)/', $range))) {
			return static::compareUsing($version, $matches['version'], $matches['operator']);
		}
		return static::compareUsing($version, $range, '=');
	}

	/**
	 * Compare two version strings with a given operator
	 *
	 * @param string $version1 The first version string
	 * @param string $version2 The second version string
	 * @param string $operator The operator to use (`<`, `=`, etc.)
	 *
	 * @return bool The result of the comparison
	 */
	public static function compareUsing(string $version1, string $version2, string $operator): bool {
		$cmp = static::compare($version1, $version2);
		switch ($operator) {
			case '<>':
			case '!=':
				return $cmp !== 0;
			case '=':
			case '==':
				return $cmp === 0;
			case '<':
				return $cmp < 0;
			case '<=':
				return $cmp <= 0;
			case '>':
				return $cmp > 0;
			case '>=':
				return $cmp >= 0;
			case '^':
				$upperLimit = ((int)explode('.', $version2)[0] + 1) . '.0.0-0';
				return $cmp >= 0 && static::compareUsing($version1, $upperLimit, '<');
			case '~':
				$parts = explode('.', $version2);
				$upperLimit = $parts[0] . '.' . ((int)$parts[1] + 1). '.0-0';
				return $cmp >= 0 && static::compareUsing($version1, $upperLimit, '<');
		}
		return false;
	}
}
