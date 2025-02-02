<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

final class Data extends AbstractDrillPacket {
	public function __construct(
		public readonly string $uuid,
		public readonly string $data,
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			uuid: substr($message, 1, 36),
			data: substr($message, 37),
		);
	}

	public function toString(): string {
		return $this->getType()->toBin() . $this->uuid . $this->data;
	}

	public function getType(): PacketType {
		return PacketType::DATA;
	}
}
