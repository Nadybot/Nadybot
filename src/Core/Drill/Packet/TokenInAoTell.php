<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

/**
 * This package is received when we choose AO-Auth to tell us which character
 * will send us the token we need to send back
 */
final class TokenInAoTell extends AbstractDrillPacket {
	/** @param string $sender name of the character sending us the token */
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
