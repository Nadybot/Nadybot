<?php declare(strict_types=1);

namespace Nadybot\Core;

class BuddylistEntry {
	/** User ID */
	public int $uid;

	/** Name */
	public string $name;

	/** Set to true if the buddy was confirmed to be on the list by AO */
	public bool $known = false;

	/** Online-status of the buddy */
	public bool $online = false;

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
