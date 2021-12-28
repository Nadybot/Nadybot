<?php declare(strict_types=1);

namespace Nadybot\Core;

class Migration {
	public function __construct(
		public string $filePath,
		public string $baseName,
		public string $timeStr,
		public string $module
	) {
	}
}
