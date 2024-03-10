<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use function Safe\pack;

use Nadybot\Modules\WEBSERVER_MODULE\Drill\PacketType;

class LetsGo extends Base {
	public function __construct(
		public string $publicUrl,
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			publicUrl: substr($message, 1),
		);
	}

	public function toString(): string {
		return pack("C", PacketType::LETS_GO) . $this->publicUrl;
	}

	public function getType(): int {
		return PacketType::LETS_GO;
	}
}
