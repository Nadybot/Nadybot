<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use EventSauce\ObjectHydrator\PropertyCasters\CastToType;

class AutoUnfreeze {
	public function __construct(
		#[CastToType('bool')]
		public bool $enabled=false,
		public ?string $login=null,
		public ?string $password=null,
		public bool $useNadyproxy=true,
	) {
		if ($this->login === "") {
			$this->login = null;
		}
		if ($this->password === "") {
			$this->password = null;
		}
	}
}
