<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Import;

use function Safe\unpack;

use Generator;

/**
 * All families of an export, packed into a single binary string
 *
 * An item as PHP array needs about 200 bytes, packed it needs 32. With tens
 * of thousands of families, this is the biggest part of an import's memory.
 */
final class FamilyStore {
	private string $data = '';
	private int $count = 0;

	/** @var array<int,string> Names of the families that have one, by family index */
	private array $names = [];

	/** @param list<array{int,int,int,int}> $items The items as [lowid, lowql, highid, highql] */
	public function add(?string $name, array $items): void {
		if (isset($name)) {
			$this->names[$this->count] = $name;
		}
		$this->data .= pack('V', count($items));
		foreach ($items as $item) {
			$this->data .= pack('q4', ...$item);
		}
		$this->count++;
	}

	/**
	 * Get all families in the order they were added
	 *
	 * @return Generator<int,array{?string,list<array{int,int,int,int}>}>
	 */
	public function families(): Generator {
		$offset = 0;
		for ($i = 0; $i < $this->count; $i++) {
			/** @var array{1:int} */
			$header = unpack('V', $this->data, $offset);
			$offset += 4;
			$items = [];
			for ($j = 0; $j < $header[1]; $j++) {
				/** @var array{1:int,2:int,3:int,4:int} */
				$item = unpack('q4', $this->data, $offset);
				$offset += 32;
				$items []= [$item[1], $item[2], $item[3], $item[4]];
			}
			yield $i => [$this->names[$i] ?? null, $items];
		}
	}
}
