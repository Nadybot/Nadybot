<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\Out;

class Message extends OutPackage {
	/** @param string|array<string,mixed> $body */
	public function __construct(
		public string $room,
		public string|array $body,
		?int $id=null,
	) {
		parent::__construct(self::MESSAGE, $id);
	}
}
