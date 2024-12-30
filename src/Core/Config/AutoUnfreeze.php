<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use Nadybot\Core\Attributes\ConvertToBool;

class AutoUnfreeze {
	public function __construct(
		#[ConvertToBool] public bool $enabled=false,
		#[ConvertToBool] public bool $useNadyproxy=true,
	) {
	}
}
