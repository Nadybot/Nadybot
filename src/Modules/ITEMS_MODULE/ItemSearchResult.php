<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class ItemSearchResult extends AODBItem {
	public function __construct(
		int $ql,
		int $lowid,
		int $highid,
		int $lowql,
		int $highql,
		string $name,
		int $icon,
		int $slot,
		int $flags,
		bool $in_game,
		int $properties,
		AodbType $type,
		bool $froob_friendly=false,
		public ?string $group_name=null,
		public ?int $group_id=null,
		public int $numExactMatches=0,
	) {
		parent::__construct(
			ql: $ql,
			lowid: $lowid,
			highid: $highid,
			lowql: $lowql,
			highql: $highql,
			name: $name,
			icon: $icon,
			slot: $slot,
			flags: $flags,
			in_game: $in_game,
			type: $type,
			froob_friendly: $froob_friendly,
			properties: $properties,
		);
	}

	/** @return ($item is null ? null : self) */
	public static function fromItem(?AODBItem $item=null): ?self {
		if (!isset($item)) {
			return null;
		}
		return new self(
			ql: $item->ql,
			lowid: $item->lowid,
			highid: $item->highid,
			lowql: $item->lowql,
			highql: $item->highql,
			name: $item->name,
			icon: $item->icon,
			slot: $item->slot->toInt(),
			flags: $item->flags->toInt(),
			in_game: $item->in_game,
			type: $item->type,
			froob_friendly: $item->froob_friendly,
			properties: $item->properties->toInt(),
		);
	}
}
