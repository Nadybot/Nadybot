<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/**
 * This interface allows a class to define how it should be textually
 * represented in the log.
 */
interface Loggable {
	/** Get the textual representation of this class to log */
	public function toLog(): string;
}
