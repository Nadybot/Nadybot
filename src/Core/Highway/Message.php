<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

class Message extends Package {
	/** @param string|array<string,mixed> $body */
	public function __construct(
		public string $room,
		public string|array $body,
		public ?string $user=null,
	) {
		$this->type = self::MESSAGE;
	}
}
