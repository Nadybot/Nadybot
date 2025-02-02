<?php declare(strict_types=1);

namespace Nadybot\Core\Drill;

use ValueError;

class PacketFactory {
	public static function parse(string $message): AbstractDrillPacket {
		$packetNum = ord(substr($message, 0, 1));
		try {
			$type = PacketType::from($packetNum);
		} catch (ValueError $e) {
			throw new UnsupportedPacketException(message: (string)$packetNum, previous: $e);
		}
		return match ($type) {
			PacketType::AO_AUTH => Packet\AoAuth::fromString($message),
			PacketType::AUTH_FAILED => Packet\AuthFailed::fromString($message),
			PacketType::CLOSED => Packet\Closed::fromString($message),
			PacketType::DATA => Packet\Data::fromString($message),
			PacketType::DISALLOWED_PACKET => Packet\DisallowedPacket::fromString($message),
			PacketType::HELLO => Packet\Hello::fromString($message),
			PacketType::LETS_GO => Packet\LetsGo::fromString($message),
			PacketType::OUT_OF_CAPACITY => Packet\OutOfCapacity::fromString($message),
			PacketType::PRESENT_TOKEN => Packet\PresentToken::fromString($message),
			PacketType::TOKEN_IN_AO_TELL => Packet\TokenInAoTell::fromString($message),
		};
	}
}
