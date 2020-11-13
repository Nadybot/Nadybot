<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

class CloseEvents {
	/** Successful operation / regular socket shutdown */
	public const NORMAL = 1000;
	/** Client is leaving (e.g. browser tab closing) */
	public const GOING_AWAY = 1001;
	/** Endpoint received a malformed frame */
	public const PROTOCOL_ERROR = 1002;
	/**
	 * Endpoint received an unsupported frame
	 * (e.g. binary-only endpoint received text frame)
	 */
	public const UNSUPPORTED  = 1003;
	/** Reserved */
	public const RESERVED = 1004;
	/** Expected close status, received none */
	public const NO_STATUS = 1005;
	/** No close code frame has been receieved */
	public const ABNORMAL = 1006;
	/** Endpoint received inconsistent message (e.g. malformed UTF-8) */
	public const UNSUPPORTED_PAYLOAD = 1007;
	/** Generic code used for situations other than 1003 and 1009 */
	public const POLICY_VIOLATION = 1008;
	/** Endpoint won't process large frame */
	public const TOO_LARGE = 1009;
	/** Client wanted an extension which server did not negotiate */
	public const MANDATORY_EXTENSION = 1010;
	/** Internal server error while operating */
	public const SERVER_ERROR = 1011;
	/** Server/service is restarting */
	public const SERVICE_RESTART = 1012;
	/** Temporary server condition forced blocking client's request */
	public const TRY_AGAIN_LATER = 1013;
	/** Server acting as gateway received an invalid response */
	public const BAD_GATEWAY = 1014;
	/** Transport Layer Security handshake failure */
	public const TLS_HANDSHAKE_FAIL = 1015;
}
