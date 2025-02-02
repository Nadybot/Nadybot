<?php declare(strict_types=1);

namespace Nadybot\Core\Drill;

use Nadybot\Core\LoggableTrait;
use Nadybot\Core\Types\Loggable;

abstract class AbstractDrillPacket implements Loggable {
	use LoggableTrait;

	abstract public static function fromString(string $message): self;

	abstract public function toString(): string;

	abstract public function getType(): PacketType;
}
