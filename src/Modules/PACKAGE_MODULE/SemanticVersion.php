<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

class SemanticVersion {
	protected string $version;

	public function __construct(string $version) {
		$this->version = $this->normalizeVersion($version);
	}

	public function __toString(): string {
		return $this->version;
	}

	public function cmp(SemanticVersion $version2): int {
		return static::compare($this->version, (string)$version2);
	}

	public function cmpStr(string $version2): int {
		return static::compare($this->version, $version2);
	}

	public static function normalizeVersion(string $version): string {
		$version = preg_replace("/@.+$/", "", $version);
		if (preg_match("/[^\d]$/", $version)) {
			$version .= "1";
		}
		$version = preg_replace("/[^a-z0-9.]+/i", ".", $version);
		$version = preg_replace("/([a-z]+)(?!\.|$)/i", "$1.", $version);
		$version = preg_replace("/^(\d+)\.(?!\d)/", "$1.0.0.", $version);
		$version = preg_replace("/^(\d+\.\d+)\.(?!\d)/", "$1.0.", $version);
		return $version;
	}

	public static function compare(string $version1, string $version2): int {
		$v1 = explode(".", static::normalizeVersion($version1));
		$v2 = explode(".", static::normalizeVersion($version2));

		for ($i = 0; $i < max(count($v1), count($v2)); $i++) {
			$t1 = $v1[$i] ?? "0";
			$t2 = $v2[$i] ?? "0";
			if (!ctype_digit($t1) && !ctype_digit($t2)) {
				$t1 = ord(substr($t1, 0, 1));
				$t2 = ord(substr($t2, 0, 1));
			} elseif (!ctype_digit($t1)) {
				return -1;
			} elseif (!ctype_digit($t2)) {
				return 1;
			}
			if (($cmp = ($t1 <=> $t2)) === 0) {
				continue;
			}
			return $cmp;
		}
		return 0;
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
		}
		return false;
	}
}
