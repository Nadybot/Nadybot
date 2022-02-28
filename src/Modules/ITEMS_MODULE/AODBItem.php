<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class AODBItem extends AODBEntry {
	public int $ql;

	public static function fromEntry(?AODBEntry $entry=null): ?self {
		if (!isset($entry)) {
			return null;
		}
		$item = new self();
		foreach (get_class_vars(AODBEntry::class) as $key => $ignore) {
			$item->{$key} = $entry->{$key};
		}
		return $item;
	}

	public function getLink(?int $ql=null, ?string $name=null): string {
		return parent::getLink($ql??$this->ql, $name);
	}
}
