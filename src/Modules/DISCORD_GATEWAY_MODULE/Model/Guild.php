<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use DateTime;

use Nadybot\Core\JSONDataModel;

class Guild extends JSONDataModel {
	/** guild id */
	public string $id;
	/** guild name (2-100 characters, excluding trailing and leading whitespace) */
	public string $name;
	/** icon hash */
	public ?string $icon;
	/** splash hash */
	public ?string $splash;
	/** discovery splash hash; only present for guilds with the "DISCOVERABLE" feature */
	public ?string $discovery_splash;
	/** true if the user is the owner of the guild */
	public bool $owner = false;
	/** id of owner */
	public string $owner_id;
	/** legacy total permissions for the user in the guild (excludes overrides) */
	public int $permissions = 0;
	/** total permissions for the user in the guild (excludes overrides) */
	public string $permissions_new;
	/** voice region id for the guild */
	public string $region;
	/** id of afk channel */
	public ?string $afk_channel_id;
	/** afk timeout in seconds */
	public int $afk_timeout;
	/** true if the server widget is enabled (deprecated, replaced with widget_enabled) */
	public bool $embed_enabled;
	/** the channel id that the widget will generate an invite to, or null if set to no invite (deprecated, replaced with widget_channel_id) */
	public ?string $embed_channel_id;
	/** verification level required for the guild */
	public int $verification_level;
	/** default message notifications level */
	public int $default_message_notifications;
	/** explicit content filter level */
	public int $explicit_content_filter;
	/**
	 * roles in the guild
	 * @var \Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\Role[]
	 */
	public array $roles = [];
	/**
	 * custom guild emojis
	 * @var Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\Emoji[]
	 */
	public array $emojis = [];
	/**
	 * enabled guild features
	 * @var string[]
	 */
	public array $features = [];
	/** required MFA level for the guild */
	public int $mfa_level;
	/** application id of the guild creator if it is bot-created */
	public ?string $application_id;
	/** true if the server widget is enabled */
	public bool $widget_enabled;
	/** the channel id that the widget will generate an invite to, or null if set to no invite */
	public ?string $widget_channel_id;
	/** the id of the channel where guild notices such as welcome messages and boost events are posted */
	public ?string $system_channel_id;
	/** system channel flags */
	public int $system_channel_flags;
	/** the id of the channel where guilds with the "PUBLIC" feature can display rules and/or guidelines */
	public ?string $rules_channel_id;
	/** when this guild was joined at */
	public ?DateTime $joined_at;
	/** true if this is considered a large guild */
	public bool $large;
	/** true if this guild is unavailable due to an outage */
	public bool $unavailable;
	/** total number of members in this guild */
	public int $member_count;
	/**
	 * states of members currently in voice channels; lacks the guild_id key
	 * @var \Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\VoiceState[]
	 */
	public array $voice_states = [];
	/**
	 * users in the guild
	 * @var \Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember[]
	 */
	public array $members = [];
	/**
	 * channels in the guild
	 * @var \Nadybot\Core\Modules\DISCORD\DiscordChannel[]
	 */
	public array $channels = [];
	/** presences of the members in the guild, will only include non-offline members if the size is greater than large threshold */
	public array $presences;
	/** the maximum number of presences for the guild (the default value, currently 25000, is in effect when null is returned) */
	public ?int $max_presences = 25000;
	/** the maximum number of members for the guild */
	public int $max_members;
	/** the vanity url code for the guild */
	public ?string $vanity_url_code;
	/** the description for the guild, if the guild is discoverable */
	public ?string $description;
	/** banner hash */
	public ?string $banner;
	/** premium tier (Server Boost level) */
	public int $premium_tier;
	/** the number of boosts this guild currently has */
	public int $premium_subscription_count;
	/** the preferred locale of a guild with the "PUBLIC" feature; used in server discovery and notices from Discord; defaults to "en-US" */
	public string $preferred_locale;
	/** the id of the channel where admins and moderators of guilds with the "PUBLIC" feature receive notices from Discord */
	public ?string $public_updates_channel_id;
	/** the maximum amount of users in a video channel */
	public int $max_video_channel_users;
	/** approximate number of members in this guild, returned from the GET /guild/<id> endpoint when with_counts is true */
	public int $approximate_member_count;
	/** approximate number of non-offline members in this guild, returned from the GET /guild/<id> endpoint when with_counts is true */
	public int $approximate_presence_count;
}
