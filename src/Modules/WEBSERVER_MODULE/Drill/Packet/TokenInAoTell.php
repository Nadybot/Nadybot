<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use function Safe\pack;

use Nadybot\Modules\WEBSERVER_MODULE\Drill\PacketType;

class TokenInAoTell extends Base {
	public function __construct(
		public string $sender,
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			sender: substr($message, 1),
		);
	}

	public function toString(): string {
		return pack('C', PacketType::TOKEN_IN_AO_TELL) . $this->sender;
	}

	public function getType(): int {
		return PacketType::TOKEN_IN_AO_TELL;
	}
}
