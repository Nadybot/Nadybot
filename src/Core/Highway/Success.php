<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

class Success extends Package {
	public function __construct(
		public string $message,
	) {
		$this->type = self::SUCCESS;
	}
}
