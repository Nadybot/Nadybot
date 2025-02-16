<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class Message extends Packet {
	public function __construct(
		public ?User $user,
		public Source $source,
		public string $message,
	) {
		parent::__construct(type: BasePacket::MESSAGE);
	}
}
