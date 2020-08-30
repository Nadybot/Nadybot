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
	public const GUILDS = 1 << 0;

	/**
	 * - GUILD_MEMBER_ADD
	 * - GUILD_MEMBER_UPDATE
	 * - GUILD_MEMBER_REMOVE
	 */
	public const GUILD_MEMBERS = 1 << 1;

	/**
	 * - GUILD_BAN_ADD
	 * - GUILD_BAN_REMOVE
	 */
	public const GUILD_BANS = 1 << 2;

	/** - GUILD_EMOJIS_UPDATE */
	public const GUILD_EMOJIS = 1 << 3;

	/** - GUILD_INTEGRATIONS_UPDATE */
	public const GUILD_INTEGRATIONS = 1 << 4;

	/** - WEBHOOKS_UPDATE */
	public const GUILD_WEBHOOKS  = 1 << 5;

	/**
	 * - INVITE_CREATE
	 * - INVITE_DELETE
	 */
	public const GUILD_INVITES = 1 << 6;

	/** - VOICE_STATE_UPDATE */
	public const GUILD_VOICE_STATES = 1 << 7;

	/** - PRESENCE_UPDATE */
	public const GUILD_PRESENCES = 1 << 8;

	/**
	 * - MESSAGE_CREATE
	 * - MESSAGE_UPDATE
	 * - MESSAGE_DELETE
	 * - MESSAGE_DELETE_BULK
	 */
	public const GUILD_MESSAGES = 1 << 9;

	/**
	 * - MESSAGE_REACTION_ADD
	 * - MESSAGE_REACTION_REMOVE
	 * - MESSAGE_REACTION_REMOVE_ALL
	 * - MESSAGE_REACTION_REMOVE_EMOJI
	 */
	public const GUILD_MESSAGE_REACTIONS = 1 << 10;

	/** - TYPING_START */
	public const GUILD_MESSAGE_TYPING = 1 << 11;

	/**
	 * - CHANNEL_CREATE
	 * - MESSAGE_CREATE
	 * - MESSAGE_UPDATE
	 * - MESSAGE_DELETE
	 * - CHANNEL_PINS_UPDATE
	 */
	public const DIRECT_MESSAGES = 1 << 12;
	
	/**
	 * - MESSAGE_REACTION_ADD
	 * - MESSAGE_REACTION_REMOVE
	 * - MESSAGE_REACTION_REMOVE_ALL
	 * - MESSAGE_REACTION_REMOVE_EMOJI
	 */
	public const DIRECT_MESSAGE_REACTIONS = 1 << 13;

	/** - TYPING_START */
	public const DIRECT_MESSAGE_TYPING = 1 << 14;
}
