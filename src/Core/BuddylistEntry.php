<?php declare(strict_types=1);

namespace Nadybot\Core;

use Stringable;

class BuddylistEntry implements Stringable {
	use StringableTrait;

	/** Unix timestamp when this entry was added to the buddylist */
	public int $added;

	/**
	 * @param int                $uid    User ID of the buddy
	 * @param string             $name   Name of the buddy
	 * @param bool               $known  Set to true if the buddy was confirmed
	 *                                   to be on the list by AO
	 * @param bool               $online Online-status of the buddy
	 * @param array<string,bool> $worker Which worker(s) holds this as their buddy
	 * @param array<string,bool> $types  Internal list to track, why someone
	 *                                   is on the buddy-list
	 */
	public function __construct(
		public int $uid,
		public string $name,
		public bool $known=false,
		public bool $online=false,
		?int $added=null,
		public array $worker=[],
		public array $types=[],
	) {
		$this->added = $added ?? time();
	}

	/** Query if $type is in the reasons, why this person is on the buddy-list */
	public function hasType(string $type): bool {
		return $this->types[$type] ?? false;
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
