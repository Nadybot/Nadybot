<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\SyncEvent;

class SyncBanEvent extends SyncEvent {
	public string $type = "sync(ban)";

	/** uid of the banned person */
	public int $uid;

	/** name of the banned person */
	public string $name;

	/** Name of the person who banned $uid */
	public string $banned_by;

	/** Reason why $uid was banned */
	public ?string $reason = null;

	/** Unix timestamp when the ban ends, or null/0 if never */
	public ?int $banned_until = null;

	/**
	 * @return array<string,int|string|null>
	 *
	 * @phpstan-return array{"uid":int, "name":string, "banned_by":?string, "banned_until":?int, "reason":?string}
	 */
	public function toData(): array {
		return [
			"uid" => $this->uid,
			"name" => $this->name,
			"banned_by" => $this->banned_by,
			"banned_until" => $this->banned_until,
			"reason" => $this->reason,
		];
	}
}
