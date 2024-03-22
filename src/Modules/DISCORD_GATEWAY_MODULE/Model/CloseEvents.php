<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Amp\Websocket\WebsocketCloseCode as WS;

class CloseEvents {
	/** Successful operation / regular socket shutdown */
	public const NORMAL = WS::NORMAL_CLOSE;

	/** Client is leaving (e.g. browser tab closing) */
	public const GOING_AWAY = WS::GOING_AWAY;

	/** Endpoint received a malformed frame */
	public const PROTOCOL_ERROR = WS::PROTOCOL_ERROR;

	/**
	 * Endpoint received an unsupported frame
	 * (e.g. binary-only endpoint received text frame)
	 */
	public const UNSUPPORTED  = WS::UNACCEPTABLE_TYPE;

	/** Reserved */
	public const RESERVED = 1_004;

	/** Expected close status, received none */
	public const NO_STATUS = WS::NONE;

	/** No close code frame has been received */
	public const ABNORMAL = WS::ABNORMAL_CLOSE;

	/** Endpoint received inconsistent message (e.g. malformed UTF-8) */
	public const UNSUPPORTED_PAYLOAD = WS::INCONSISTENT_FRAME_DATA_TYPE;

	/** Generic code used for situations other than 1003 and 1009 */
	public const POLICY_VIOLATION = WS::POLICY_VIOLATION;

	/** Endpoint won't process large frame */
	public const TOO_LARGE = WS::MESSAGE_TOO_LARGE;

	/** Client wanted an extension which server did not negotiate */
	public const MANDATORY_EXTENSION = WS::EXPECTED_EXTENSION_MISSING;

	/** Internal server error while operating */
	public const SERVER_ERROR = WS::UNEXPECTED_SERVER_ERROR;

	/** Server/service is restarting */
	public const SERVICE_RESTART = WS::SERVICE_RESTARTING;

	/** Temporary server condition forced blocking client's request */
	public const TRY_AGAIN_LATER = WS::TRY_AGAIN_LATER;

	/** Server acting as gateway received an invalid response */
	public const BAD_GATEWAY = WS::BAD_GATEWAY;

	/** Transport Layer Security handshake failure */
	public const TLS_HANDSHAKE_FAIL = WS::TLS_HANDSHAKE_FAILURE;

	/** We're not sure what went wrong. Try reconnecting? */
	public const UNKNOWN_ERROR = 4_000;

	/** You sent an invalid Gateway opcode or an invalid payload for an opcode. Don't do that! */
	public const UNKNOWN_OPCODE = 4_001;

	/** You sent an invalid payload to us. Don't do that! */
	public const DECODE_ERROR = 4_002;

	/** You sent us a payload prior to identifying. */
	public const NOT_AUTHENTICATED = 4_003;

	/** The account token sent with your identify payload is incorrect. */
	public const AUTHENTICATION_FAILED = 4_004;

	/** You sent more than one identify payload. Don't do that! */
	public const ALREADY_AUTHENTICATED = 4_005;

	/** The sequence sent when resuming the session was invalid. Reconnect and start a new session. */
	public const INVALID_SEQ = 4_007;

	/** Woah nelly! You're sending payloads to us too quickly. Slow it down! You will be disconnected on receiving this. */
	public const RATE_LIMITED = 4_008;

	/** Your session timed out. Reconnect and start a new one. */
	public const SESSION_TIMED_OUT = 4_009;

	/** You sent us an invalid shard when identifying. */
	public const INVALID_SHARD = 4_010;

	/** The session would have handled too many guilds - you are required to shard your connection in order to connect. */
	public const SHARDING_REQUIRED = 4_011;

	/** You sent an invalid version for the gateway. */
	public const INVALID_API_VERSION = 4_012;

	/** You sent an invalid intent for a Gateway Intent. You may have incorrectly calculated the bitwise value. */
	public const INVALID_INTENT = 4_013;

	/** You sent a disallowed intent for a Gateway Intent. You may have tried to specify an intent that you have not enabled or are not whitelisted for. */
	public const DISALLOWED_INTENT = 4_014;
}
