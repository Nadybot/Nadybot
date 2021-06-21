<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\DBRow;

class LEProc extends DBRow {
	public int $id;
	public string $profession;
	public string $name;
	public string $research_name;
	public int $research_lvl;
	public int $proc_type = 1;
	public int $chance = 0;
	public string $modifiers;
	public string $duration;
	public string $proc_trigger;
	public string $description;
}
