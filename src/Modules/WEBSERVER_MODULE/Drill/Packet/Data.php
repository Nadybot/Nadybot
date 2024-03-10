<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use function Safe\pack;

use Nadybot\Modules\WEBSERVER_MODULE\Drill\PacketType;

class Data extends Base {
	public function __construct(
		public string $uuid,
		public string $data,
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			uuid: substr($message, 1, 36),
			data: substr($message, 37),
		);
	}

	public function toString(): string {
		return pack("C", PacketType::DATA) . $this->uuid . $this->data;
	}

	public function getType(): int {
		return PacketType::DATA;
	}
}
