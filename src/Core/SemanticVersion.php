<?php declare(strict_types=1);

namespace Nadybot\Core;

class SemanticVersion {
	protected string $origVersion;
	protected string $version;

	public function __construct(string $version) {
		$this->version = $this->normalizeVersion($this->origVersion = $version);
	}

	public function __toString(): string {
		return $this->version;
	}

	public function getOrigVersion(): string {
		return $this->origVersion;
	}

	public function cmp(SemanticVersion $version2): int {
		return static::compare($this->version, (string)$version2);
	}

	public function cmpStr(string $version2): int {
		return static::compare($this->version, $version2);
	}

	public static function normalizeVersion(string $version): string {
		$version = preg_replace("/@.+$/", "", strtolower($version));
		if (preg_match("/[^\d]$/", $version)) {
			$version .= "1";
		}
		$version = preg_replace("/[^a-z0-9.]+/i", ".", $version);
		$version = preg_replace("/^(\d+)\.(?!\d)/", "$1.0.0.", $version);
		$version = preg_replace("/^(\d+\.\d+)\.(?!\d)/", "$1.0.", $version);
		return $version;
	}

	public static function compare(string $version1, string $version2): int {
		$v1 = explode(".", static::normalizeVersion($version1));
		$v2 = explode(".", static::normalizeVersion($version2));

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
			if (($cmp = ($t1 <=> $t2)) === 0) {
				continue;
			}
			return $cmp;
		}
		return 0;
	}

	/**
	 * Check if $version is in range of $range
	 *
	 * @param string $range
	 * @param string $version
	 * @return boolean
	 */
	public static function inMask(string $range, string $version): bool {
		$version = strtolower($version);
		$range = strtolower($range);
		if (preg_match("/^(?<operator>[<>!=~^]+)(?<version>[0-9a-z.-]+)/", $range, $matches)) {
			return static::compareUsing($version, $matches['version'], $matches['operator']);
		}
		return static::compareUsing($version, $range, '=');
	}

	public static function compareUsing(string $version1, string $version2, string $operator): bool {
		$cmp = static::compare($version1, $version2);
		switch ($operator) {
			case "<>":
			case "!=":
				return $cmp !== 0;
			case "=":
			case "==":
				return $cmp === 0;
			case "<":
				return $cmp < 0;
			case "<=":
				return $cmp <= 0;
			case ">":
				return $cmp > 0;
			case ">=":
				return $cmp >= 0;
			case "^":
				$upperLimit = ((int)explode(".", $version2)[0] + 1) . ".0.0-0";
				return $cmp >= 0 && static::compareUsing($version1, $upperLimit, "<");
			case "~":
				$parts = explode(".", $version2);
				$upperLimit = $parts[0] . "." . ((int)$parts[1] + 1). ".0-0";
				return $cmp >= 0 && static::compareUsing($version1, $upperLimit, "<");
		}
		return false;
	}
}
