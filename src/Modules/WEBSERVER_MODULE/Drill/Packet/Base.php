<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use Nadybot\Core\LoggableTrait;
use Nadybot\Core\Types\Loggable;

abstract class Base implements Loggable {
	use LoggableTrait;

	public function toLog(): string {
		return $this->traitedToLog(hide: ['logger']);
	}

	abstract public static function fromString(string $message): self;

	abstract public function toString(): string;

	abstract public function getType(): int;
}
