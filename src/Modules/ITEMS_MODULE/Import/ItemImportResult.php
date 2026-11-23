<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Import;

/** Statistics about an import of item data */
final class ItemImportResult {
	public string $version = '';

	/** Number of families in the document */
	public int $families = 0;

	/** Number of templates in the document */
	public int $templates = 0;

	/** Number of templates that were not turned into items by the import filter */
	public int $filteredTemplates = 0;

	/** Number of item rows created from families */
	public int $familyItems = 0;

	/** Number of item rows created from templates not referenced by a family */
	public int $directItems = 0;

	public int $groups = 0;
	public int $buffs = 0;
	public int $types = 0;
	public int $weapons = 0;

	/** Number of items that are not in the game */
	public int $notInGame = 0;

	/** @var array<string,int> Number of skipped entries, by reason */
	public array $skipped = [];

	/** Number of uncompressed bytes read */
	public int $bytes = 0;

	public function skip(string $reason): void {
		$this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
	}

	public function items(): int {
		return $this->familyItems + $this->directItems;
	}
}
