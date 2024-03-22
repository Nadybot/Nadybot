<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

class Result extends InPackage {
	public string $message;

	public function __construct(
		string $type,
		public ?string $room,
		public null|string|int $id,
		?string $message,
		?string $body,
	) {
		parent::__construct($type);
		$this->message = $message ?? $body ?? '';
	}
}
