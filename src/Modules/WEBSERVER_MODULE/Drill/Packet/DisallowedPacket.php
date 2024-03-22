<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use function Safe\pack;

use Nadybot\Modules\WEBSERVER_MODULE\Drill\PacketType;

class DisallowedPacket extends Base {
	public static function fromString(string $message): self {
		return new self();
	}

	public function toString(): string {
		return pack('C', PacketType::DISALLOWED_PACKET);
	}

	public function getType(): int {
		return PacketType::DISALLOWED_PACKET;
	}
}
