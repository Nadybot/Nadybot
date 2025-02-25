<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use Nadybot\Core\Drill\{AbstractDrillPacket, PacketType};

/** This packet is sent when authenticating via AO-Auth to send back the received token */
final class PresentToken extends AbstractDrillPacket {
	/**
	 * @param string $token           The token we received
	 * @param string $desiredSudomain The subdomain we want (bot's name)
	 */
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
