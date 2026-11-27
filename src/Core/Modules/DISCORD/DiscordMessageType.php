<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

class DiscordMessageType {
	public const int DEFAULT                                = 0;
	public const int RECIPIENT_ADD                          = 1;
	public const int RECIPIENT_REMOVE                       = 2;
	public const int CALL                                   = 3;
	public const int CHANNEL_NAME_CHANGE                    = 4;
	public const int CHANNEL_ICON_CHANGE                    = 5;
	public const int CHANNEL_PINNED_MESSAGE                 = 6;
	public const int GUILD_MEMBER_JOIN                      = 7;
	public const int USER_PREMIUM_GUILD_SUBSCRIPTION        = 8;
	public const int USER_PREMIUM_GUILD_SUBSCRIPTION_TIER_1 = 9;
	public const int USER_PREMIUM_GUILD_SUBSCRIPTION_TIER_2 = 10;
	public const int USER_PREMIUM_GUILD_SUBSCRIPTION_TIER_3 = 11;
	public const int CHANNEL_FOLLOW_ADD                     = 12;
	public const int GUILD_DISCOVERY_DISQUALIFIED           = 14;
	public const int GUILD_DISCOVERY_REQUALIFIED            = 15;
}
