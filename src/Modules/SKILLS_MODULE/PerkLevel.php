<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class PerkLevel extends DBRow {
	/**
	 * @param int                   $perk_id          The internal ID of the perk line
	 * @param int                   $perk_level       Which level of $perk_id does this represent?
	 * @param int                   $required_level   Required character level to perk this perk level
	 * @param ?int                  $aoid             The internal ID of the perk level in AO
	 * @param string[]              $professions
	 * @param array<int,int>        $buffs
	 * @param ExtPerkLevelBuff[]    $perk_buffs
	 * @param array<int,int>        $resistances
	 * @param PerkLevelResistance[] $perk_resistances
	 */
	public function __construct(
		public int $perk_id,
		public int $perk_level,
		public int $required_level,
		public ?int $id=null,
		public ?int $aoid=null,
		#[NCA\DB\Ignore] public array $professions=[],
		#[NCA\DB\Ignore] public array $buffs=[],
		#[NCA\DB\Ignore] public array $perk_buffs=[],
		#[NCA\DB\Ignore] public array $resistances=[],
		#[NCA\DB\Ignore] public array $perk_resistances=[],
		#[NCA\DB\Ignore] public ?PerkLevelAction $action=null,
	) {
	}
}
