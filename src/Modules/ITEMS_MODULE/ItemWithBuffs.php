<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class ItemWithBuffs extends AODBEntry {
	/** @var ExtBuff[] */
	public array $buffs = [];

	public static function fromEntry(AODBEntry $item): self {
		return new self(
			lowid: $item->lowid,
			highid: $item->highid,
			lowql: $item->lowql,
			highql: $item->highql,
			name: $item->name,
			icon: $item->icon,
			slot: $item->slot->toInt(),
			flags: $item->flags->toInt(),
			type: $item->type,
			in_game: $item->in_game,
			froob_friendly: $item->froob_friendly,
			properties: $item->properties->toInt(),
		);
	}
}
