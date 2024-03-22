<?php declare(strict_types=1);

namespace Nadybot\Core;

class AnonBinData implements Loggable {
	public function __construct(
		private ?string $data,
	) {
	}

	public function toLog(): string {
		if ($this->data === null) {
			return 'null';
		}
		return '0x' . implode('', str_split(bin2hex($this->data), 2));
	}
}
