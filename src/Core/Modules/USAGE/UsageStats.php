<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE;

class UsageStats {
	public string $id;
	public int $version = 2;
	public bool $debug = false;
	public object $commands;
	public SettingsUsageStats $settings;
}
