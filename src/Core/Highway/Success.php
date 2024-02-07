<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

class Success extends Package {
	public string $message;

	public function __construct(
		public ?string $room,
		?string $message,
		?string $body,
		?string $id,
	) {
		parent::__construct(self::SUCCESS, $id);
		$this->message = $message ?? $body ?? "";
	}
}
