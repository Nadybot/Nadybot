<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** Use this class to log a string as binary data */
class AnonBinData implements Loggable {
	/** @param ?string $data The data to log as binary */
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
