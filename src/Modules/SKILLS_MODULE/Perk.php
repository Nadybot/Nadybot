<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\DBRow;

class Perk extends DBRow {
	/** Internal ID of that perkline */
	public int $id;

	/** Name of the perk */
	public string $name;

	/** The expansion needed for this perk */
	public string $expansion = "sl";

	/**
	 * @db:ignore
	 * @var array<int,PerkLevel>
	 */
	public array $levels = [];
}
