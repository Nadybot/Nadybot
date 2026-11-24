<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Import;

use Nadybot\Core\DBTable;

/** Receives the rows the item importer creates */
interface ItemRowSink {
	/**
	 * Add a row to a table
	 *
	 * @param class-string<DBTable>         $table The class describing the table
	 * @param array<string,null|int|string> $row   The row to add
	 */
	public function add(string $table, array $row): void;

	/** Write all rows that are still buffered */
	public function flush(): void;
}
