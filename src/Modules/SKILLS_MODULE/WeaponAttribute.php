<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\DBRow;

class WeaponAttribute extends DBRow {
	public int $id;
	public int $attack_time;
	public int $recharge_time;
	public ?int $full_auto;
	public ?int $burst;
	public bool $fling_shot;
	public bool $fast_attack;
	public bool $aimed_shot;
	public bool $brawl;
	public bool $sneak_attack;
	public ?int $multi_m;
	public ?int $multi_r;
}
