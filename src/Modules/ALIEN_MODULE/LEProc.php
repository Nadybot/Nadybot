<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\DBRow;

class LEProc extends DBRow {
	public string $profession;
	public string $name;
	public ?string $research_name = null;
	public int $research_lvl;
	public ?string $proc_type = null;
	public ?string $chance = null;
	public string $modifiers;
	public string $duration;
	public string $proc_trigger;
	public string $description;
}
