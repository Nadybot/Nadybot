<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE;

class SettingsUsageStats {
	public int $dimension;
	public bool $is_guild_bot;
	public string $guildsize;
	public bool $using_chat_proxy;
	public string $db_type;
	public string $bot_version;
	public bool $using_git;
	public string $os;
	public string $symbol;
	public bool $relay_enabled;
	public int $relay_type;
	public bool $first_and_last_alt_only;
	public string $aodb_db_version;
	public int $max_blob_size;
	public int $online_show_org_guild;
	public int $online_show_org_priv;
	public bool $online_admin;
	public int $relay_symbol_method;
	public bool $http_server_enable;
	public int $tower_attack_spam;
}
