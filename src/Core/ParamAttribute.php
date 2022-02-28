<?php declare(strict_types=1);

namespace Nadybot\Core;

use ReflectionParameter;

interface ParamAttribute {
	public function renderParameter(ReflectionParameter $param): string;
	public function getRegexp(): string;
}
