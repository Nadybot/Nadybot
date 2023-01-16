<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

class Error extends Package {
	public function __construct(
		public string $message,
	) {
		$this->type = self::ERROR;
	}
}
