<?php declare(strict_types=1);

namespace Nadybot\Core;

use Monolog\Handler\AbstractHandler;

/**
 * Dedup
 */
class DedupHandler extends AbstractHandler {
	private ?string $lastLog = null;

	/**
	 * {@inheritDoc}
	 */
	public function handle(array $record): bool {
		$rec = $record;
		unset($rec["datetime"]);
		$serialized = serialize($rec);
		if (isset($this->lastLog) && $this->lastLog === $serialized) {
			return true;
		}
		$this->lastLog = $serialized;
		return false;
	}
}
