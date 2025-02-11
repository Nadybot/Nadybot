<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE;

class UsageStats {
	/** @param array<string,int> $commands */
	public function __construct(
		public string $id,
		public array $commands,
		public SettingsUsageStats $settings,
		public int $version=2,
		public bool $debug=false,
	) {
	}
}
