<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

final class Closed extends AbstractDrillPacket {
	public function __construct(
		public readonly string $uuid,
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			uuid: substr($message, 1, 32),
		);
	}

	public function toString(): string {
		return $this->getType()->toBin() . $this->uuid;
	}

	public function getType(): PacketType {
		return PacketType::CLOSED;
	}
}
