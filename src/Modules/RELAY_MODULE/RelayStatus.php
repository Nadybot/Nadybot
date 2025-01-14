<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

/** The status of a relay = error, warning, or ready */
class RelayStatus {
	public function __construct(
		public RelayStatusType $type=RelayStatusType::ERROR,
		public string $text='Unknown',
	) {
	}

	public function toString(): string {
		$color = $this->type->getColor();
		return "<{$color}>{$this->text}<end>";
	}
}
