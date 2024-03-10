<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use function Safe\pack;

use Nadybot\Modules\WEBSERVER_MODULE\Drill\PacketType;

class AoAuth extends Base {
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
		return pack("C", PacketType::AO_AUTH) . $this->characterName;
	}

	public function getType(): int {
		return PacketType::AO_AUTH;
	}
}
