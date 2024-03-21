<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\DBRow;

class WorldBossTimer extends DBRow {
	public int $time_submitted;

	public function __construct(
		public string $mob_name,
		public int $spawn,
		public int $killable,
		public string $submitter_name,
		?int $time_submitted=null,
		public ?int $timer=null,
		public ?int $next_spawn=null,
		public ?int $next_killable=null,
	) {
		$this->time_submitted = $time_submitted ?? time();
	}
}
