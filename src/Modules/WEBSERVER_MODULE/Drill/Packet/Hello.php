<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use function Safe\unpack;

use Nadybot\Modules\WEBSERVER_MODULE\Drill\PacketType;

class Hello extends Base {
	public function __construct(
		public int $protoVersion,
		public int $authMode,
		public string $description,
	) {
	}

	public static function fromString(string $message): self {
		$data = unpack("Ctype/nproto_version/Cauth_mode/Z*description", $message);
		return new self(
			protoVersion: $data['proto_version'],
			authMode: $data['auth_mode'],
			description: $data['description'],
		);
	}

	public function toString(): string {
		return pack(
			"CnCZ*",
			PacketType::HELLO,
			$this->protoVersion,
			$this->authMode,
			$this->description
		);
	}

	public function getType(): int {
		return PacketType::HELLO;
	}
}
