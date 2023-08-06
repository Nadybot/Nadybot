<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use Nadybot\Modules\WEBSERVER_MODULE\Drill\PacketType;

class PresentToken extends Base {
	public function __construct(
		public string $token,
		public string $desiredSudomain="",
	) {
	}

	public static function fromString(string $message): self {
		return new self(
			token: substr($message, 1, 36),
			desiredSudomain: substr($message, 37),
		);
	}

	public function toString(): string {
		return pack("C", PacketType::PRESENT_TOKEN) . $this->token . $this->desiredSudomain;
	}

	public function getType(): int {
		return PacketType::PRESENT_TOKEN;
	}
}
