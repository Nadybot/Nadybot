<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

class SiteInfo extends TowerSite {
	public int $id;
	public string $long_name;
	public ?string $short_name;
}
