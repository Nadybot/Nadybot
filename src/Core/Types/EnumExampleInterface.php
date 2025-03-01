<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/**
 * This is an interface that can be used so that enums
 * used as a command parameter can define an example.
 */
interface EnumExampleInterface {
	/** Get the text that's given as an example in the help page */
	public static function getExample(): string;
}
