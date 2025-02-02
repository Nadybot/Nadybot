<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

final class AoAuth extends AbstractDrillPacket {
	public function __construct(
		public string $characterName,
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			characterName: substr($message, 1),
		);
	}

	public function toString(): string {
		return $this->getType()->toBin() . $this->characterName;
	}

	public function getType(): PacketType {
		return PacketType::AO_AUTH;
	}
}
