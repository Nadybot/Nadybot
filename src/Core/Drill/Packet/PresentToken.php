<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

final class PresentToken extends AbstractDrillPacket {
	public function __construct(
		public string $token,
		public string $desiredSudomain='',
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			token: substr($message, 1, 36),
			desiredSudomain: substr($message, 37),
		);
	}

	public function toString(): string {
		return $this->getType()->toBin() . $this->token . $this->desiredSudomain;
	}

	public function getType(): PacketType {
		return PacketType::PRESENT_TOKEN;
	}
}
