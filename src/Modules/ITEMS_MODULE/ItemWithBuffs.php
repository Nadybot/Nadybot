<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class ItemWithBuffs extends AODBEntry {
	/** @var ExtBuff[] */
	public array $buffs = [];
}
