<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use EventSauce\ObjectHydrator\MapFrom;

class Mob {
	public const T_PRISONER = 'prisoner';
	public const T_HAG = 'hag';
	public const T_DREAD = 'dreadloch';
	public const T_UNIQUES = 'unique';
	public const T_LEGCHOPPER = 'legchopper';

	public const STATUS_UP = 'up';
	public const STATUS_DOWN = 'down';
	public const STATUS_ATTACKED = 'under_attack';
	public const STATUS_OUT_OF_RANGE = 'out_of_range';
	public const STATUS_UNKNOWN = 'unknown';

	private const NAME_MAPPING = [
		self::T_HAG => [
			'omni-zn' => 'Omni Hag 1 - ZN (%s)',
			'omni-or' => 'Omni Hag 2 - OR (%s)',
			'omni-e'  => 'Omni Hag 3 - E (%s)',
			'clan-no' => 'Clan Hag 1 - NO (%s)',
			'clan-ph' => 'Clan Hag 3 - PH (%s)',
			'clan-ex' => 'Clan Hag 2 - EX (%s)',
		],
		self::T_LEGCHOPPER => [
			'clone1' => 'Clone #1 (%s)',
			'clone2' => 'Clone #2 (%s)',
			'clone3' => 'Clone #3 (%s)',
			'clone4' => 'Clone #4 (%s)',
			'clone5' => 'Clone #5 (%s)',
		],
		self::T_DREAD => [
			'moxy' => [
				'Unexplained Alien Tower' => 'Special Agent Moxy (Placeholder)',
			],
			'deko' => [
				'Mysterious Alien Tower' => 'Special Agent Deko (Placeholder)',
			],
		],
	];

	public function __construct(
		public string $name,
		public string $key,
		public string $type,
		#[MapFrom('coordinates.x', '.')] public int $x,
		#[MapFrom('coordinates.y', '.')] public int $y,
		#[MapFrom('playfield')] public int $playfield_id,
		public ?int $instance,
		#[MapFrom('status.status', '.')] public string $status,
		#[MapFrom('status.last_killed', '.')] public ?int $last_killed,
		#[MapFrom('status.hp_percent', '.')] public ?float $hp_percent,
		public ?int $respawn_timer,
		#[MapFrom('status.last_seen', '.')] public ?int $last_seen=null,
	) {
		$this->fixName();
	}

	public function fixName(): void {
		$newMask = self::NAME_MAPPING[$this->type][$this->key] ?? null;
		if (isset($newMask)) {
			if (is_array($newMask)) {
				if (isset($newMask[$this->name])) {
					$this->name = $newMask[$this->name];
				}
			} else {
				$this->name = sprintf($newMask, $this->name);
			}
		}
	}
}
