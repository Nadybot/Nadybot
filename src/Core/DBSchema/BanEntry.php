<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class BanEntry extends DBRow {
	/** uid of the banned person */
	public int $charid;

	/** Name of the person who banned $charid */
	public ?string $admin;

	/** Unix time stamp when $charid was banned */
	public ?int $time;

	/** Reason why $charid was banned */
	public ?string $reason;

	/** Unix timestamp when the ban ends, or null/0 if never */
	public ?int $banend;

	/** @db:ignore */
	public string $name;
}
