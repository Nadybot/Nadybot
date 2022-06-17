<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class PerkLevel extends DBRow {
	public int $id;

	/** The internal ID of the perk level in AO */
	public ?int $aoid = null;

	/** The internal ID of the perk like */
	public int $perk_id;

	/** Which level of $perk_id does this represent? */
	public int $perk_level;

	/** Required character level to perk this perk level */
	public int $required_level;

	/**
	 * @var string[]
	 */
	#[NCA\DB\Ignore]
	public array $professions = [];

	/**
	 * @var array<int,int>
	 */
	#[NCA\DB\Ignore]
	public array $buffs = [];

	/**
	 * @var ExtPerkLevelBuff[]
	 */
	#[NCA\DB\Ignore]
	public array $perk_buffs = [];

	/**
	 * @var array<int,int>
	 */
	#[NCA\DB\Ignore]
	public array $resistances = [];

	/**
	 * @var PerkLevelResistance[]
	 */
	#[NCA\DB\Ignore]
	public array $perk_resistances = [];

	#[NCA\DB\Ignore]
	public ?PerkLevelAction $action = null;
}
