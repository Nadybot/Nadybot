<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class ItemBuffSearchResult extends AODBEntry {
	public int $amount;
	public ?int $low_amount;
	public ?int $multi_m;
	public ?int $multi_r;
}
