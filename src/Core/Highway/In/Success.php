<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

class Success extends InPackage {
	public string $message;

	public function __construct(
		public ?string $room,
		?string $message,
		?string $body,
		public ?string $id,
	) {
		parent::__construct(self::SUCCESS);
		$this->message = $message ?? $body ?? "";
	}
}
