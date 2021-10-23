<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\DBRow;

class WorldbossTimer extends DBRow {
	public string $mob_name;
	public int $timer;
	public int $spawn;
	public int $killable;
	public ?int $next_spawn;
	public ?int $next_killable;
	public int $time_submitted;
	public string $submitter_name;
}
