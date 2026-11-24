<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Import;

/**
 * A set of AOIDs, stored as a bit field
 *
 * One bit per possible ID needs far less memory than an array with one
 * entry per ID: 5 million IDs fit into 625 KB. IDs outside the bit field's
 * range are stored in an array instead.
 */
final class IdSet {
	/** IDs from here on are not stored in the bit field, that would need more than 8 MB */
	private const MAX_BIT_ID = 1 << 26;

	private string $bits = '';

	/** @var array<int,true> */
	private array $others = [];

	public function add(int $id): void {
		if ($id < 0 || $id >= self::MAX_BIT_ID) {
			$this->others[$id] = true;
			return;
		}
		$byte = $id >> 3;
		$length = strlen($this->bits);
		if ($byte >= $length) {
			$this->bits .= str_repeat("\0", max($byte + 1 - $length, $length));
		}
		$this->bits[$byte] = chr(ord($this->bits[$byte]) | (1 << ($id & 7)));
	}

	public function has(int $id): bool {
		if ($id < 0 || $id >= self::MAX_BIT_ID) {
			return isset($this->others[$id]);
		}
		$byte = $id >> 3;
		if ($byte >= strlen($this->bits)) {
			return false;
		}
		return (ord($this->bits[$byte]) & (1 << ($id & 7))) !== 0;
	}
}
