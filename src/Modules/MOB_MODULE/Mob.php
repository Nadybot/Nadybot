<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use EventSauce\ObjectHydrator\MapFrom;

class Mob {
	public const T_PRISONER = "prisoner";
	public const T_HAG = "hag";
	public const T_DREAD = "dreadloch";

	public const STATUS_UP = "up";
	public const STATUS_DOWN = "down";
	public const STATUS_ATTACKED = "under_attack";

	public function __construct(
		public string $name,
		public string $key,
		public string $type,
		#[MapFrom("coordinates.x", ".")] public int $x,
		#[MapFrom("coordinates.x", ".")] public int $y,
		#[MapFrom("playfield")] public int $playfield_id,
		public ?int $instance,
		#[MapFrom("status.status", ".")] public string $status,
		#[MapFrom("status.last_killed", ".")] public ?int $last_killed,
		#[MapFrom("status.hp_percent", ".")] public ?float $hp_percent,
		public ?int $respawn_timer,
	) {
	}
}
