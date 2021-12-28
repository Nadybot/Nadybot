<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\DBRow;

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
	 * @db:ignore
	 * @var string[]
	 */
	public array $professions = [];

	/**
	 * @db:ignore
	 * @var array<int,int>
	 */
	public array $buffs = [];

	/**
	 * @db:ignore
	 * @var ExtPerkLevelBuff[]
	 */
	public array $perk_buffs = [];

	/**
	 * @db:ignore
	 * @var array<int,int>
	 */
	public array $resistances = [];

	/**
	 * @db:ignore
	 * @var PerkLevelResistance[]
	 */
	public array $perk_resistances = [];

	/** @db:ignore */
	public ?PerkLevelAction $action = null;
}
