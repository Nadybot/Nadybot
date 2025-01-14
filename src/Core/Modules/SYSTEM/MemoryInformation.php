<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

/** Memory usage statistics for the bot */
class MemoryInformation {
	/**
	 * @param int $available          Maximum available memory for PHP in bytes
	 * @param int $current_usage      Current memory usage in bytes
	 * @param int $current_usage_real Current memory usage in bytes including allocated system pages
	 * @param int $peak_usage         Peak memory usage in bytes
	 * @param int $peak_usage_real    Peak memory usage in bytes including allocated system pages
	 */
	public function __construct(
		public int $available,
		public int $current_usage,
		public int $current_usage_real,
		public int $peak_usage,
		public int $peak_usage_real,
	) {
	}
}
