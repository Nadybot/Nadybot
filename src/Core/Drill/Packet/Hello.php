<?php declare(strict_types=1);

namespace Nadybot\Core\Drill\Packet;

use function Safe\{pack, unpack};
use Nadybot\Core\Drill\{AbstractDrillPacket, DrillAuthMode, PacketType};

/** This packet is received when successfully connecting to the server */
final class Hello extends AbstractDrillPacket {
	/**
	 * @param int           $protoVersion Protocol version the server supports
	 * @param DrillAuthMode $authMode     Auth mode the server runs with
	 * @param string        $description  A short description about the server
	 */
	public function __construct(
		public readonly int $protoVersion,
		public readonly DrillAuthMode $authMode,
		public readonly string $description,
	) {
	}

	public static function fromString(string $message): self {
		$data = unpack('Ctype/nproto_version/Cauth_mode/Z*description', $message);
		return new self(
			protoVersion: $data['proto_version'],
			authMode: DrillAuthMode::from($data['auth_mode']),
			description: $data['description'],
		);
	}

	public function toString(): string {
		return $this->getType()->toBin().
			pack(
				'nCZ*',
				$this->protoVersion,
				$this->authMode->value,
				$this->description
			);
	}

	public function getType(): PacketType {
		return PacketType::HELLO;
	}
}
