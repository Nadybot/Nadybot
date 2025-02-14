<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use Nadybot\Core\Attributes\Hydrator\ConvertToBool;

/** Settings for the auto-unfreezer */
class AutoUnfreeze {
	public function __construct(
		#[ConvertToBool] public bool $enabled=false,
		#[ConvertToBool] public bool $useNadyproxy=true,
	) {
	}
}
