<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class MemoryInformation {
	/** Current memory usage in bytes */
	public int $current_usage;

	/** Current memory usage in bytes including allocated system pages */
	public int $current_usage_real;

	/** Peak memory usage in bytes */
	public int $peak_usage;

	/** Peak memory usage in bytes including allocated system pages */
	public int $peak_usage_real;
}
