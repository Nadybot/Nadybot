<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

class Error extends Package {
	public function __construct(
		public string $message,
		public ?string $room,
		?string $id,
	) {
		parent::__construct(self::ERROR, $id);
	}
}
