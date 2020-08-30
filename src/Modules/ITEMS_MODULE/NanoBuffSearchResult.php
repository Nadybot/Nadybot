<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class NanoBuffSearchResult extends Buff {
	public ?string $use_name;
	public int $amount;
	public ?int $lowid;
	public ?int $highid;
	public ?int $lowql;
	public ?int $low_ncu;
	public ?int $low_amount;
}
