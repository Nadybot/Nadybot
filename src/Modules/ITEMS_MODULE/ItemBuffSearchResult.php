<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class ItemBuffSearchResult extends AODBEntry {
	public function __construct(
		public int $amount,
		int $lowid,
		int $highid,
		int $lowql,
		int $highql,
		string $name,
		int $icon,
		int $slot,
		int $flags,
		bool $in_game,
		AodbType $type,
		bool $froob_friendly=false,
		public ?int $low_amount=null,
		public ?int $multi_m=null,
		public ?int $multi_r=null,
	) {
		parent::__construct(
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
		);
	}
}
