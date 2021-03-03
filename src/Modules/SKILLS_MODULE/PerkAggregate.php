<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

class PerkAggregate {
	/** Internal ID of that perkline */
	public int $id;

	/** Name of the perk */
	public string $name;

	/** An optional description of the perk */
	public ?string $description;

	/** The expansion needed for this perk */
	public string $expansion = "sl";

	/** @var string[] */
	public array $professions;

	public int $max_level = 1;

	/**
	 * @var PerkLevelBuff[]
	 */
	public array $buffs = [];

	/**
	 * @var PerkLevelResistance[]
	 */
	public array $resistances = [];

	/**
	 * @var PerkLevelAction[]
	 */
	public array $actions = [];
}
