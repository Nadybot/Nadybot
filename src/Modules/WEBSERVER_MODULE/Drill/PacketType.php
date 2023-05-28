<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill;

class PacketType {
	public const HELLO = 1;
	public const AO_AUTH = 2;
	public const TOKEN_IN_AO_TELL = 3;
	public const PRESENT_TOKEN = 4;
	public const LETS_GO = 5;
	public const AUTH_FAILED = 6;
	public const OUT_OF_CAPACITY = 7;
	public const DISALLOWED_PACKET = 8;
	public const DATA = 9;
	public const CLOSED = 10;
}
