<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

/** This packet is received after successfully authenticating to the server */
final class LetsGo extends AbstractDrillPacket {
	/** @param string $publicUrl The full URL that can now be used to reach this bot */
	public function __construct(
		public readonly string $publicUrl,
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			publicUrl: substr($message, 1),
		);
	}

	public function toString(): string {
		return $this->getType()->toBin() . $this->publicUrl;
	}

	public function getType(): PacketType {
		return PacketType::LETS_GO;
	}
}
