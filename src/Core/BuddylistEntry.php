<?php declare(strict_types=1);

namespace Nadybot\Core;

class BuddylistEntry {
	/** User ID of the buddy */
	public int $uid;

	/** Name of the buddy */
	public string $name;

	/** Set to true if the buddy was confirmed to be on the list by AO */
	public bool $known = false;

	/** Online-status of the buddy */
	public bool $online = false;

	/**
	 * Which worker(s) holds this as their buddy
	 * @var array<int,bool>
	 */
	public array $worker = [];

	/**
	 * Internal list to track, why someone is on the buddy-list
	 * @var array<string,bool>
	 */
	public array $types = [];

	/** Query if $type is in the reasons, why this person is on the buddy-list */
	public function hasType(string $type): bool {
		return ($this->types[$type] ?? false);
	}

	/** Add $type to the reasons, why this person is on the buddy-list */
	public function setType(string $type): void {
		$this->types[$type] = true;
	}

	/** Remove $type from the reasons, why this person is on the buddy-list */
	public function unsetType(string $type): void {
		unset($this->types[$type]);
	}
}
