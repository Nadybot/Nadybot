<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill;

class PacketFactory {
	/** @var array<int,class-string> */
	public const CLASS_MAP = [
		PacketType::AO_AUTH => Packet\AoAuth::class,
		PacketType::AUTH_FAILED => Packet\AuthFailed::class,
		PacketType::CLOSED => Packet\Closed::class,
		PacketType::DATA => Packet\Data::class,
		PacketType::DISALLOWED_PACKET => Packet\DisallowedPacket::class,
		PacketType::HELLO => Packet\Hello::class,
		PacketType::LETS_GO => Packet\LetsGo::class,
		PacketType::OUT_OF_CAPACITY => Packet\OutOfCapacity::class,
		PacketType::PRESENT_TOKEN => Packet\PresentToken::class,
		PacketType::TOKEN_IN_AO_TELL => Packet\TokenInAoTell::class,
	];

	public static function parse(string $message): Packet\Base {
		$type = ord(substr($message, 0, 1));
		$class = self::CLASS_MAP[$type]??null;
		if (!isset($class) || !is_a($class, Packet\Base::class, true)) {
			throw new UnsupportedPacketException((string)$type);
		}
		return $class::fromString($message);
	}
}
