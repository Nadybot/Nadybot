<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Import;

use Nadybot\Core\{DB, DBTable};

/** Writes the rows of the item importer to the database in chunks */
final class DBRowSink implements ItemRowSink {
	/** @var array<class-string<DBTable>,list<array<string,null|int|string>>> */
	private array $rows = [];

	/** @var array<class-string<DBTable>,int> */
	private array $chunkSize = [];

	/** @var array<class-string<DBTable>,int> */
	private array $counts = [];

	public function __construct(
		private DB $db,
	) {
	}

	/** {@inheritDoc} */
	public function add(string $table, array $row): void {
		$this->rows[$table] ??= [];
		$this->rows[$table] []= $row;
		$this->chunkSize[$table] ??= max(1, intdiv($this->db->maxPlaceholders, count($row)));
		if (count($this->rows[$table]) >= $this->chunkSize[$table]) {
			$this->write($table);
		}
	}

	public function flush(): void {
		foreach (array_keys($this->rows) as $table) {
			$this->write($table);
		}
	}

	/** @return array<class-string<DBTable>,int> Number of rows written, by table */
	public function getCounts(): array {
		return $this->counts;
	}

	/** @param class-string<DBTable> $table */
	private function write(string $table): void {
		$rows = $this->rows[$table] ?? [];
		if (!count($rows)) {
			return;
		}
		$this->db->table($table::getTable())->insert($rows);
		$this->counts[$table] = ($this->counts[$table] ?? 0) + count($rows);
		$this->rows[$table] = [];
	}
}
