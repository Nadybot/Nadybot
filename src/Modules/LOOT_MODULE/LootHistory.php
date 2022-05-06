<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBRow;

class LootHistory extends DBRow {
	/** Artificial primary key */
	public int $id;

	/** The n-th roll on this bot */
	public int $roll;

	/** Position on the loot table (starting with 1) */
	public int $pos;

	/** Simple name (without HTML) of the item */
	public string $name;

	/** Unix timestamp when it was rolled */
	public int $dt;

	/** How many items of this in total were rolled in this slot */
	public int $amount = 1;

	/** Funcom aodb icon id */
	public ?int $icon = null;

	/** Who added this item to the loot table */
	public string $added_by;

	/** Who did the !flatroll */
	public string $rolled_by;

	/** Nice display (including item link) of the item */
	public string $display = "";

	/** Optional comment about the item */
	public string $comment = "";

	/** If someone won the item, name of the winner */
	public ?string $winner = null;

	/** @var string[] */
	#[NCA\DB\Ignore]
	public array $winners = [];
}
