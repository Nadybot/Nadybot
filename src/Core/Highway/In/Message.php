<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

class Message extends InPackage {
	/** @param string|array<string,mixed> $body */
	public function __construct(
		string $type,
		public string $room,
		public string|array $body,
		public string $user,
	) {
		parent::__construct($type);
	}
}
