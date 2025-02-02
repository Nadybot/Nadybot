<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType, PacketType as DrillPacketType};

final class AuthFailed extends AbstractDrillPacket {
	public static function fromString(string $message): self {
		return new self();
	}

	public function toString(): string {
		return $this->getType()->toBin();
	}

	public function getType(): DrillPacketType {
		return PacketType::AUTH_FAILED;
	}
}
