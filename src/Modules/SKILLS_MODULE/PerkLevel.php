<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes\DB, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[DB\Table(name: 'perk_level', shared: DB\Shared::Yes)]
class PerkLevel extends DBTable {
	#[DB\PK] public UuidInterface $id;

	/**
	 * @param UuidInterface             $perk_id          The internal ID of the perk line
	 * @param int                       $perk_level       Which level of $perk_id does this represent?
	 * @param int                       $required_level   Required character level to perk this perk level
	 * @param ?int                      $aoid             The internal ID of the perk level in AO
	 * @param list<string>              $professions
	 * @param array<int,int>            $buffs
	 * @param list<ExtPerkLevelBuff>    $perk_buffs
	 * @param array<int,int>            $resistances
	 * @param list<PerkLevelResistance> $perk_resistances
	 */
	public function __construct(
		public UuidInterface $perk_id,
		public int $perk_level,
		public int $required_level,
		?UuidInterface $id=null,
		public ?int $aoid=null,
		#[DB\Ignore] public array $professions=[],
		#[DB\Ignore] public array $buffs=[],
		#[DB\Ignore] public array $perk_buffs=[],
		#[DB\Ignore] public array $resistances=[],
		#[DB\Ignore] public array $perk_resistances=[],
		#[DB\Ignore] public ?PerkLevelAction $action=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
