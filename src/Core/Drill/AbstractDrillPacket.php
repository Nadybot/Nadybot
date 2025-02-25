<?php declare(strict_types=1);

namespace Nadybot\Core\Drill;

use Nadybot\Core\LoggableTrait;
use Nadybot\Core\Types\Loggable;

/**
 * This is an basic abstract drill packet that only has some basic functionality
 * to parse or serialize
 */
abstract class AbstractDrillPacket implements Loggable {
	use LoggableTrait;

	/** Create a new instance based on the binary data given */
	abstract public static function fromString(string $message): self;

	/** Get a binary representation of this packet */
	abstract public function toString(): string;

	/** Get the type of this packet */
	abstract public function getType(): PacketType;
}
