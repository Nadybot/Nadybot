<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE;

class SettingsUsageStats {
	/**
	 * @param string[] $relay_protocols
	 *
	 * @psalm-param list<string> $relay_protocols
	 */
	public function __construct(
		public int $dimension,
		public bool $is_guild_bot,
		public string $guildsize,
		public int $num_workers,
		public string $db_type,
		public string $fs_type,
		public string $bot_version,
		public bool $using_git,
		public string $os,
		public string $symbol,
		public int $num_relays,
		public array $relay_protocols,
		public string $aodb_db_version,
		public int $max_blob_size,
		public int $online_show_org_guild,
		public int $online_show_org_priv,
		public bool $online_admin,
		public bool $http_server_enable,
		public string $drill_server,
	) {
	}
}
