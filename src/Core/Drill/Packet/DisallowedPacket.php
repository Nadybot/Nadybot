<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

final class DisallowedPacket extends AbstractDrillPacket {
	public static function fromString(string $message): self {
		return new self();
	}

	public function toString(): string {
		return $this->getType()->toBin();
	}

	public function getType(): PacketType {
		return PacketType::DISALLOWED_PACKET;
	}
}
