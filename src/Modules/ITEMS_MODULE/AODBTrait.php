<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

trait AODBTrait {
	public int $lowid;
	public int $highid;
	public int $lowql;
	public int $highql;
	public string $name;
	public int $icon;
	public bool $froob_friendly = false;
	public int $slot;
	public int $flags;

	public function getLink(?int $ql=null, ?string $name=null): string {
		$ql ??= $this->lowql;
		$name ??= $this->name;
		return "<a href='itemref://{$this->lowid}/{$this->highid}/{$ql}'>{$name}</a>";
	}
}
