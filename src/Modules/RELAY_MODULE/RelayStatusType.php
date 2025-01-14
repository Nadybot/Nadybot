<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

/** The status of a relay = error, warning, or ready */
enum RelayStatusType: string {
	public function getColor(): string {
		return match ($this) {
			self::ERROR => 'off',
			self::INIT => 'yellow',
			self::READY => 'on',
		};
	}

	case ERROR = 'error';
	case INIT = 'warning';
	case READY = 'ready';
}
