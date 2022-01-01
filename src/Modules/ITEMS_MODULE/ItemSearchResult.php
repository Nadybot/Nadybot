<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class ItemSearchResult extends AODBEntry {
	public ?string $group_name;
	public ?int $group_id;
	public int $ql;
	public int $numExactMatches = 0;

	public static function fromItem(?AODBEntry $item=null): ?self {
		if (!isset($item)) {
			return null;
		}
		$obj = new self();
		foreach (get_object_vars($item) as $key => $value) {
			$obj->{$key} = $value;
		}
		return $obj;
	}
}
