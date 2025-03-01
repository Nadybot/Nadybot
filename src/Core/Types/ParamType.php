<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** The name and specs of a function parameter */
enum ParamType: string {
	case Secret = 'secret';
	case String = 'string';
	case StringArray = 'string[]';
	case Int = 'int';
	case Bool = 'bool';
}
