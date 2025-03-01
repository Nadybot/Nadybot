<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

/** This packet is received when the server doesn't have capacity to tunnel us */
final class OutOfCapacity extends AbstractDrillPacket {
	public static function fromString(string $message): self {
		return new self();
	}

	public function toString(): string {
		return $this->getType()->toBin();
	}

	public function getType(): PacketType {
		return PacketType::OUT_OF_CAPACITY;
	}
}
