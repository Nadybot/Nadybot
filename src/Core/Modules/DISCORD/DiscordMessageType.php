<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

class DiscordMessageType {
	public const DEFAULT                                = 0;
	public const RECIPIENT_ADD                          = 1;
	public const RECIPIENT_REMOVE                       = 2;
	public const CALL                                   = 3;
	public const CHANNEL_NAME_CHANGE                    = 4;
	public const CHANNEL_ICON_CHANGE                    = 5;
	public const CHANNEL_PINNED_MESSAGE                 = 6;
	public const GUILD_MEMBER_JOIN                      = 7;
	public const USER_PREMIUM_GUILD_SUBSCRIPTION        = 8;
	public const USER_PREMIUM_GUILD_SUBSCRIPTION_TIER_1 = 9;
	public const USER_PREMIUM_GUILD_SUBSCRIPTION_TIER_2 = 10;
	public const USER_PREMIUM_GUILD_SUBSCRIPTION_TIER_3 = 11;
	public const CHANNEL_FOLLOW_ADD                     = 12;
	public const GUILD_DISCOVERY_DISQUALIFIED           = 14;
	public const GUILD_DISCOVERY_REQUALIFIED            = 15;
}
