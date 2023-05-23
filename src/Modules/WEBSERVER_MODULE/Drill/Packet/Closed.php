<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use Nadybot\Modules\WEBSERVER_MODULE\Drill\PacketType;

class Closed extends Base {
	public function __construct(
		public string $uuid,
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			uuid: substr($message, 1, 32),
		);
	}

	public function toString(): string {
		return pack("C", PacketType::CLOSED) . $this->uuid;
	}

	public function getType(): int {
		return PacketType::CLOSED;
	}
}
