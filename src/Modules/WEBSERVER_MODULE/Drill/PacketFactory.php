<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill;

class PacketFactory {
	public static function parse(string $message): Packet\Base {
		$type = ord(substr($message, 0, 1));
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
			default => throw new UnsupportedPacketException((string)$type),
		};
	}
}
