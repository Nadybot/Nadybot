<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

class Success extends Package {
	public string $message;

	public function __construct(
		?string $message,
		?string $body,
	) {
		$this->message = $message ?? $body ?? "";
		$this->type = self::SUCCESS;
	}
}
