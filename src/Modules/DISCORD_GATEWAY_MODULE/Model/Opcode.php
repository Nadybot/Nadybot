<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

class Opcode {
	/**
	 * An event was dispatched.
	 *
	 * @var int
	 */
	public const DISPATCH = 0;

	/**
	 * Fired periodically by the client to keep the connection alive.
	 *
	 * @var int
	 */
	public const HEARTBEAT = 1;

	/**
	 * Starts a new session during the initial handshake.
	 *
	 * @var int
	 */
	public const IDENTIFY = 2;

	/**
	 * Update the client's presence.
	 *
	 * @var int
	 */
	public const PRESENCE_UPDATE = 3;

	/**
	 * Used to join/leave or move between voice channels.
	 *
	 * @var int
	 */
	public const VOICE_STATE_UPDATE = 4;

	/**
	 * Resume a previous session that was disconnected.
	 *
	 * @var int
	 */
	public const RESUME = 6;

	/**
	 * You should attempt to reconnect and resume immediately.
	 *
	 * @var int
	 */
	public const RECONNECT = 7;

	/**
	 * Request information about offline guild members in a large guild.
	 *
	 * @var int
	 */
	public const REQUEST_GUILD_MEMBERS = 8;

	/** The session has been invalidated. You should reconnect and identify/resume accordingly. */
	public const INVALID_SESSION = 9;

	/** Sent immediately after connecting, contains the heartbeat_interval to use. */
	public const HELLO = 10;

	/** Sent in response to receiving a heartbeat to acknowledge that it has been received. */
	public const HEARTBEAT_ACK = 11;
}
