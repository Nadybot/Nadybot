<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

class Error extends InPackage {
	public function __construct(
		public string $message,
		public ?string $room,
		public ?string $id,
	) {
		parent::__construct(self::ERROR);
	}
}
