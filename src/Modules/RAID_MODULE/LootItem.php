<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

class LootItem {
	public string $name;

	public ?int $icon;

	public string $added_by;

	public string $display;

	public string $comment = "";

	public int $multiloot = 1;

	/** @var array<string,bool> */
	public array $users = [];
}
