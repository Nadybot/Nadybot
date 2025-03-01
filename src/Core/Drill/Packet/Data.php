<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

/** This packet is sent/received when a connection by a client sends/receives data */
final class Data extends AbstractDrillPacket {
	/**
	 * @param string $uuid UUID of the connection
	 * @param string $data The data that was received
	 */
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
