<?php declare(strict_types=1);

namespace Nadybot\Core;

use Stringable;

class Migration implements Stringable {
	use StringableTrait;

	/** @param class-string $className */
	public function __construct(
		public string $filePath,
		public string $baseName,
		public string $className,
		public int $order,
		public string $module,
		public bool $shared,
	) {
	}
}
