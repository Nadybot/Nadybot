<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Modules\ITEMS_MODULE\ExtBuff;

class PerkAggregate {
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
	 * @var array<int,int>
	 */
	public array $buffs = [];

	/**
	 * @var array<int,int>
	 */
	public array $resistances = [];

	/**
	 * @var PerkLevelAction[]
	 */
	public array $actions = [];
}
