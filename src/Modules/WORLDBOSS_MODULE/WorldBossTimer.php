<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\DBRow;

class WorldBossTimer extends DBRow {
	public string $mob_name;
	public ?int $timer = null;
	public int $spawn;
	public int $killable;
	public ?int $next_spawn = null;
	public ?int $next_killable = null;
	public int $time_submitted;
	public string $submitter_name;
}
