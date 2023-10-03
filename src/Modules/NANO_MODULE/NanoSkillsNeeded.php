<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

class NanoSkillsNeeded {
	public function __construct(
		public ?int $mc=null,
		public ?int $ts=null,
		public ?int $pm=null,
		public ?int $si=null,
		public ?int $mm=null,
		public ?int $bm=null,
	) {
	}
}
