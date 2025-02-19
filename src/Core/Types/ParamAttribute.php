<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use ReflectionParameter;

/**
 * This is the interface that attributes can implement
 * so that they can be used as modifiers to a command parameter
 */
interface ParamAttribute {
	/** How should the parameter be rendered by the help */
	public function renderParameter(ReflectionParameter $param): string;

	/** Which regular expression should this attribute stand for */
	public function getRegexp(): string;
}
