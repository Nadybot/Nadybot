<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Modules\WEBSOCKET_MODULE\CloseEvents as WsCloseEvents;

class CloseEvents extends WsCloseEvents {
	/** We're not sure what went wrong. Try reconnecting? */
	public const UNKNOWN_ERROR = 4000;
	/** You sent an invalid Gateway opcode or an invalid payload for an opcode. Don't do that! */
	public const UNKNOWN_OPCODE = 4001;
	/** You sent an invalid payload to us. Don't do that! */
	public const DECODE_ERROR = 4002;
	/** You sent us a payload prior to identifying. */
	public const NOT_AUTHENTICATED = 4003;
	/** The account token sent with your identify payload is incorrect. */
	public const AUTHENTICATION_FAILED = 4004;
	/** You sent more than one identify payload. Don't do that! */
	public const ALREADY_AUTHENTICATED = 4005;
	/** The sequence sent when resuming the session was invalid. Reconnect and start a new session. */
	public const INVALID_SEQ = 4007;
	/** Woah nelly! You're sending payloads to us too quickly. Slow it down! You will be disconnected on receiving this. */
	public const RATE_LIMITED = 4008;
	/** Your session timed out. Reconnect and start a new one. */
	public const SESSION_TIMED_OUT = 4009;
	/** You sent us an invalid shard when identifying. */
	public const INVALID_SHARD = 4010;
	/** The session would have handled too many guilds - you are required to shard your connection in order to connect. */
	public const SHARDING_REQUIRED = 4011;
	/** You sent an invalid version for the gateway. */
	public const INVALID_API_VERSION = 4012;
	/** You sent an invalid intent for a Gateway Intent. You may have incorrectly calculated the bitwise value. */
	public const INVALID_INTENT = 4013;
	/** You sent a disallowed intent for a Gateway Intent. You may have tried to specify an intent that you have not enabled or are not whitelisted for. */
	public const DISALLOWED_INTENT = 4014;
}
