<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class ItemSearchResult extends AODBEntry {
	public ?string $group_name;
	public ?int $group_id;
	public int $ql;
}
