<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\{DBRow, Profession};

class LEProc extends DBRow {
	public function __construct(
		public int $id,
		public Profession $profession,
		public string $name,
		public string $research_name,
		public int $research_lvl,
		public string $modifiers,
		public string $duration,
		public string $proc_trigger,
		public string $description,
		public int $proc_type=1,
		public int $chance=0,
	) {
	}
}
