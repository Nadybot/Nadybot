<?php declare(strict_types=1);

namespace Nadybot\Core\Drill;

use function Safe\pack;

/** This is an enum for all valid drill packet types */
enum PacketType: int {
	public function toBin(): string {
		return pack('C', $this->value);
	}

	case HELLO = 1;
	case AO_AUTH = 2;
	case TOKEN_IN_AO_TELL = 3;
	case PRESENT_TOKEN = 4;
	case LETS_GO = 5;
	case AUTH_FAILED = 6;
	case OUT_OF_CAPACITY = 7;
	case DISALLOWED_PACKET = 8;
	case DATA = 9;
	case CLOSED = 10;
}
