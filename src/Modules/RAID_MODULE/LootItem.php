<?php

namespace Nadybot\Modules\RAID_MODULE;

class LootItem {
	/** @var string */
	public $name;

	/** @var int */
	public $icon;

	/** @var string */
	public $added_by;

	/** @var string */
	public $display;

	/** @var string */
	public $comment = "";

	/** @var int */
	public $multiloot = 1;

	/** @var array<string,bool> */
	public $users = [];
}
