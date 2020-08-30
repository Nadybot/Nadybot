<?php declare(strict_types=1);

namespace Nadybot\Core;

class WebsocketErrorEvent extends Event {
	public const UNKNOWN_ERROR = 0;
	public const INVALID_URL = 1;
	public const INVALID_SCHEME = 2;
	public const CONNECT_TIMEOUT = 3;
	public const CONNECT_ERROR = 4;
	public const WEBSOCKETS_NOT_SUPPORTED = 5;
	public const INVALID_UPGRADE_RESPONSE = 6;
	public const WRITE_ERROR = 7;
	public const BAD_OPCODE = 8;

	public WebsocketClient $websocket;
	public int $errorCode = self::UNKNOWN_ERROR;
}
