<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

class Intent {
	/**
	 * - GUILD_CREATE
	 * - GUILD_UPDATE
	 * - GUILD_DELETE
	 * - GUILD_ROLE_CREATE
	 * - GUILD_ROLE_UPDATE
	 * - GUILD_ROLE_DELETE
	 * - CHANNEL_CREATE
	 * - CHANNEL_UPDATE
	 * - CHANNEL_DELETE
	 * - CHANNEL_PINS_UPDATE
	 */
	public const int GUILDS = 1 << 0;

	/**
	 * - GUILD_MEMBER_ADD
	 * - GUILD_MEMBER_UPDATE
	 * - GUILD_MEMBER_REMOVE
	 */
	public const int GUILD_MEMBERS = 1 << 1;

	/**
	 * - GUILD_BAN_ADD
	 * - GUILD_BAN_REMOVE
	 */
	public const int GUILD_BANS = 1 << 2;

	/** - GUILD_EMOJIS_UPDATE */
	public const int GUILD_EMOJIS = 1 << 3;

	/** - GUILD_INTEGRATIONS_UPDATE */
	public const int GUILD_INTEGRATIONS = 1 << 4;

	/** - WEBHOOKS_UPDATE */
	public const int GUILD_WEBHOOKS  = 1 << 5;

	/**
	 * - INVITE_CREATE
	 * - INVITE_DELETE
	 */
	public const int GUILD_INVITES = 1 << 6;

	/** - VOICE_STATE_UPDATE */
	public const int GUILD_VOICE_STATES = 1 << 7;

	/** - PRESENCE_UPDATE */
	public const int GUILD_PRESENCES = 1 << 8;

	/**
	 * - MESSAGE_CREATE
	 * - MESSAGE_UPDATE
	 * - MESSAGE_DELETE
	 * - MESSAGE_DELETE_BULK
	 */
	public const int GUILD_MESSAGES = 1 << 9;

	/**
	 * - MESSAGE_REACTION_ADD
	 * - MESSAGE_REACTION_REMOVE
	 * - MESSAGE_REACTION_REMOVE_ALL
	 * - MESSAGE_REACTION_REMOVE_EMOJI
	 */
	public const int GUILD_MESSAGE_REACTIONS = 1 << 10;

	/** - TYPING_START */
	public const int GUILD_MESSAGE_TYPING = 1 << 11;

	/**
	 * - CHANNEL_CREATE
	 * - MESSAGE_CREATE
	 * - MESSAGE_UPDATE
	 * - MESSAGE_DELETE
	 * - CHANNEL_PINS_UPDATE
	 */
	public const int DIRECT_MESSAGES = 1 << 12;

	/**
	 * - MESSAGE_REACTION_ADD
	 * - MESSAGE_REACTION_REMOVE
	 * - MESSAGE_REACTION_REMOVE_ALL
	 * - MESSAGE_REACTION_REMOVE_EMOJI
	 */
	public const int DIRECT_MESSAGE_REACTIONS = 1 << 13;

	/** - TYPING_START */
	public const int DIRECT_MESSAGE_TYPING = 1 << 14;

	/** - MESSAGE_CONTENT */
	public const int MESSAGE_CONTENT = 1 << 15;

	/**
	 * - GUILD_SCHEDULED_EVENT_CREATE
	 * - GUILD_SCHEDULED_EVENT_UPDATE
	 * - GUILD_SCHEDULED_EVENT_DELETE
	 * - GUILD_SCHEDULED_EVENT_USER_ADD
	 * - GUILD_SCHEDULED_EVENT_USER_REMOVE
	 */
	public const int GUILD_SCHEDULED_EVENTS = 1 << 16;
}
