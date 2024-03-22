<?php declare(strict_types=1);

namespace Nadybot\Core;

use Monolog\Handler\AbstractHandler;

/**
 * Dedup
 */
class DedupHandler extends AbstractHandler {
	/** @var null|array<string,mixed> */
	private ?array $lastRecord = null;

	/** {@inheritDoc} */
	public function handle(array $record): bool {
		$rec = $record;
		unset($rec['datetime']);
		if (!isset($this->lastRecord)) {
			$this->lastRecord = $rec;
			return false;
		}
		$keys = array_unique(array_merge(array_keys($rec), array_keys($this->lastRecord)));
		foreach ($keys as $key) {
			$new = ($rec[$key]??null);
			$old = ($this->lastRecord[$key]??null);
			if ($new === $old) {
				continue;
			}
			if (!is_array($new) || !is_array($old)) {
				$this->lastRecord = $rec;
				return false;
			}
			$subKeys = array_unique(array_merge(array_keys($new), array_keys($old)));
			foreach ($subKeys as $subKey) {
				if (($new[$subKey]??null) === ($old[$subKey]??null)) {
					continue;
				}
				$this->lastRecord = $rec;
				return false;
			}
		}
		return true;
	}
}
