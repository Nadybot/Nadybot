<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

final class TokenInAoTell extends AbstractDrillPacket {
	public function __construct(
		public readonly string $sender,
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			sender: substr($message, 1),
		);
	}

	public function toString(): string {
		return $this->getType()->toBin() . $this->sender;
	}

	public function getType(): PacketType {
		return PacketType::TOKEN_IN_AO_TELL;
	}
}
